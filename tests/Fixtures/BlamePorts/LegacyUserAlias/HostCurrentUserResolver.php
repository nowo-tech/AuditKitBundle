<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyUserAlias;

use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\User\UserInterface;

/** A host replacement of the 1.14 user port registered by autodiscovery (`#[AsAlias]`), deprecated in id mode since 1.15. */
#[AsAlias(CurrentUserResolverInterface::class)]
final class HostCurrentUserResolver implements CurrentUserResolverInterface
{
    public function resolve(): ?UserInterface
    {
        return null;
    }
}
