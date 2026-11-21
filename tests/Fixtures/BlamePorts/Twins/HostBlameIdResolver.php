<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\Twins;

use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\AuditActor;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The framework-free blame port, aliased by autodiscovery: the one the id mode reads. */
#[AsAlias(BlameIdResolverInterface::class)]
final class HostBlameIdResolver implements BlameIdResolverInterface
{
    public function resolveBlameId(ProfileSettings $profile, ?AuditActor $actor): ?string
    {
        return $actor?->id === null ? null : 'host:' . $actor->id;
    }
}
