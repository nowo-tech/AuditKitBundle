<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

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
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.15, `blame_mode: id` only: keeps a host override of the 1.14 ports working (BC) and reports it as deprecated.
 *
 * - A host alias of {@see BlameValueResolverInterface} (while {@see BlameIdResolverInterface} keeps its default) is
 *   still what the listener calls.
 * - A host alias of {@see CurrentUserResolverInterface} (while {@see CurrentActorProviderInterface} keeps its default)
 *   is still read, through {@see SecurityCurrentActorProvider}.
 *
 * @internal
 */
final class BlamePortsPass implements CompilerPassInterface
{
    public const PACKAGE = 'nowo-tech/audit-kit-bundle';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_audit_kit.blame_mode')
            || $container->getParameter('nowo_audit_kit.blame_mode') !== 'id'
            || !$container->hasDefinition(AuditableEntityListener::class)) {
            return;
        }

        if ($this->target($container, CurrentUserResolverInterface::class) !== CurrentUserResolver::class
            && $this->target($container, CurrentActorProviderInterface::class) === SecurityCurrentActorProvider::class) {
            trigger_deprecation(self::PACKAGE, '1.15', 'With "nowo_audit_kit.blame_mode: id", overriding "%s" is deprecated: alias "%s" to your service instead. 2.0 no longer reads the former in id mode.', CurrentUserResolverInterface::class, CurrentActorProviderInterface::class);
        }

        if ($this->target($container, BlameValueResolverInterface::class) !== UserIdBlameValueResolver::class
            && $this->target($container, BlameIdResolverInterface::class) === ActorIdBlameIdResolver::class) {
            trigger_deprecation(self::PACKAGE, '1.15', 'With "nowo_audit_kit.blame_mode: id", overriding "%s" is deprecated: alias "%s" to your service instead. 2.0 no longer reads the former in id mode.', BlameValueResolverInterface::class, BlameIdResolverInterface::class);

            $container->getDefinition(AuditableEntityListener::class)
                ->setArgument('$blameIdResolver', null)
                ->setArgument('$blameValueResolver', new Reference(BlameValueResolverInterface::class));
        }
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
