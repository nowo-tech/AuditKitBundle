<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyDecorator;

use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\SecurityUser;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A host decorator of the 1.14 user port registered by autodiscovery (`#[AsDecorator]`). 1.x gave it no notice in id
 * mode, and the default actor provider still reads it in 2.0: allowed. It supplies the actor when the token has none
 * (as a Messenger worker replaying the command's initiator would).
 */
#[AsDecorator(CurrentUserResolverInterface::class)]
final readonly class HostCurrentUserResolver implements CurrentUserResolverInterface
{
    public const ACTOR_ID = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f70';

    public function __construct(#[AutowireDecorated] private CurrentUserResolverInterface $inner)
    {
    }

    public function resolve(): UserInterface
    {
        return $this->inner->resolve() ?? new SecurityUser(Uuid::fromString(self::ACTOR_ID), 'worker@example.com');
    }
}
