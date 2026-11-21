<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\Twins;

use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The framework-free actor port, aliased by autodiscovery. */
#[AsAlias(CurrentActorProviderInterface::class)]
final class HostActorProvider implements CurrentActorProviderInterface
{
    public function currentActor(): AuditActor
    {
        return new AuditActor('u-1');
    }
}
