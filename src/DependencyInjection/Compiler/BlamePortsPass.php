<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuditKitBundle\Blame\ActorIdBlameIdResolver;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\UserIdBlameValueResolver;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function implode;
use function sprintf;

/**
 * `blame_mode: id` only. Since 1.15 the id mode runs on the framework-free ports ({@see CurrentActorProviderInterface},
 * {@see BlameIdResolverInterface}); 1.15–1.18 kept a host override of the 1.14 ports working with a deprecation notice.
 * Since 2.0 the container build fails instead, naming the twin to alias (owner decision Q6), while the twin keeps the
 * bundle default:
 *
 * - {@see BlameValueResolverInterface}: an alias, a service registered under its id, or a decorator (attributes such
 *   as `#[AsAlias]` / `#[AsDecorator]` included). The id mode no longer reads it, and ignoring it silently would write
 *   other blame ids than the host expects. 1.15–1.18 already ignored its decorators in id mode, without a notice.
 * - {@see CurrentUserResolverInterface}: an alias or a service registered under its id, the overrides the 1.15 notice
 *   announced. Its decorators stay allowed: 1.x gave them no notice, and the default actor provider
 *   ({@see SecurityCurrentActorProvider}) still reads them.
 *
 * Reference mode (the default) still uses the 1.14 ports and is left alone.
 *
 * @internal
 */
final class BlamePortsPass implements CompilerPassInterface
{
    public const PACKAGE = 'nowo-tech/audit-kit-bundle';

    /**
     * 1.14 port => [its bundle default, the framework-free twin, the twin's bundle default, whether decorators count].
     *
     * @var array<class-string, array{class-string, class-string, class-string, bool}>
     */
    public const LEGACY_PORTS = [
        CurrentUserResolverInterface::class => [CurrentUserResolver::class, CurrentActorProviderInterface::class, SecurityCurrentActorProvider::class, false],
        BlameValueResolverInterface::class  => [UserIdBlameValueResolver::class, BlameIdResolverInterface::class, ActorIdBlameIdResolver::class, true],
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_audit_kit.blame_mode')
            || $container->getParameter('nowo_audit_kit.blame_mode') !== 'id'
            || !$container->hasDefinition(AuditableEntityListener::class)) {
            return;
        }

        foreach (self::LEGACY_PORTS as $legacy => [$legacyDefault, $twin, $twinDefault, $decorators]) {
            if ($this->target($container, $twin) !== $twinDefault) {
                continue;
            }

            $overrides = $this->overrides($container, $legacy, $legacyDefault, $decorators);
            if ($overrides !== []) {
                throw new LogicException(sprintf('With "nowo_audit_kit.blame_mode: id", the service(s) "%s" override "%s", which the id mode no longer supports since nowo-tech/audit-kit-bundle 2.0 (deprecated since 1.15). Alias "%s" to your service instead (see UPGRADING.md, "To 2.0.0").', implode('", "', $overrides), $legacy, $twin));
            }
        }
    }

    /**
     * The host services that replace $legacy: the alias target or the service registered under its id when it is not
     * the bundle default, and, when $decorators, every decorator of it.
     *
     * @return list<string>
     */
    private function overrides(ContainerBuilder $container, string $legacy, string $legacyDefault, bool $decorators): array
    {
        $overrides = [];
        $target    = $this->target($container, $legacy);
        if ($target !== null && $target !== $legacyDefault) {
            $overrides[] = $target;
        }

        if (!$decorators) {
            return $overrides;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if (($definition->getDecoratedService()[0] ?? null) === $legacy) {
                $overrides[] = $id;
            }
        }

        return $overrides;
    }

    /** The service an interface id points to: the alias target, the id itself for a definition, null when absent. */
    private function target(ContainerBuilder $container, string $id): ?string
    {
        if ($container->hasAlias($id)) {
            return (string) $container->getAlias($id);
        }

        return $container->hasDefinition($id) ? $id : null;
    }
}
