<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Since 2.2: falls back to nowo-tech/correlation-contract's `NullCorrelationIdProvider` for
 * `CorrelationIdProviderInterface` only when neither the host nor another package bound the interface, so the bundle
 * never replaces a provider someone else registered (the contract's "consume an optional port" rule).
 *
 * @internal
 */
final class CorrelationProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(CorrelationIdProviderInterface::class)) {
            $container->setAlias(CorrelationIdProviderInterface::class, NowoAuditKitExtension::NULL_CORRELATION_PROVIDER);
        }
    }
}
