<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;

/**
 * Since 2.2:
 *
 * - falls back to nowo-tech/correlation-contract's `NullCorrelationIdProvider` for `CorrelationIdProviderInterface`
 *   only when neither the host nor another package bound the interface, so the bundle never replaces a provider
 *   someone else registered (the contract's "consume an optional port" rule);
 * - refuses `correlation.provider: true` together with a host binding of the interface: the bundle's listeners would
 *   keep a context nobody reads. Choose one provider.
 *
 * @internal
 */
final class CorrelationProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $interface = CorrelationIdProviderInterface::class;
        if (!$container->has($interface)) {
            $container->setAlias($interface, NowoAuditKitExtension::NULL_CORRELATION_PROVIDER);

            return;
        }

        if (!$container->hasDefinition(CorrelationIdHolder::class)) {
            return;
        }

        $bound = $container->hasAlias($interface) ? (string) $container->getAlias($interface) : $interface;
        if ($bound !== CorrelationIdHolder::class) {
            throw new LogicException(sprintf('"nowo_audit_kit.correlation.provider: true" registers the bundle\'s correlation provider, but %s is bound to "%s". Remove that binding, or set "provider: false" to use your own provider.', $interface, $bound));
        }
    }
}
