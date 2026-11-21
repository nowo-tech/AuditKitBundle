<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyDecorator;

use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\User\UserInterface;

/** A host decorator of the 1.14 user port registered by autodiscovery (`#[AsDecorator]`), read in id mode by 1.15–1.18. */
#[AsDecorator(CurrentUserResolverInterface::class)]
final readonly class HostCurrentUserResolver implements CurrentUserResolverInterface
{
    public function __construct(#[AutowireDecorated] private CurrentUserResolverInterface $inner)
    {
    }

    public function resolve(): ?UserInterface
    {
        return $this->inner->resolve();
    }
}
