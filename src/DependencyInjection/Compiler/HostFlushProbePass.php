<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Since 1.18: when transaction-doctrine's Symfony bundle is registered, the action log uses its shared host-flush probe
 * ({@see HostFlushProbe::SERVICE_ID}, reset at its own unit-of-work boundaries) instead of the bundle's instance. Without
 * it the bundle's instance stays, reset by kernel.reset and by the bundle's boundary subscriber.
 *
 * @internal
 */
final class HostFlushProbePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE)
            || (!$container->hasDefinition(HostFlushProbe::SERVICE_ID) && !$container->hasAlias(HostFlushProbe::SERVICE_ID))) {
            return;
        }

        $container->removeDefinition(NowoAuditKitExtension::HOST_FLUSH_PROBE);
        $container->setAlias(NowoAuditKitExtension::HOST_FLUSH_PROBE, HostFlushProbe::SERVICE_ID);
    }
}
