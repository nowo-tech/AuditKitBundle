<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Blame;

use Nowo\AuditKitBundle\Blame\ActorIdBlameIdResolver;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class ActorIdBlameIdResolverTest extends TestCase
{
    public function testReturnsTheActorId(): void
    {
        $profile = new ProfileSettings('default', UserInterface::class, true, ProfileRegistryFactory::defaultFields(), 'datetime_immutable', true, true);

        $this->assertSame('u-1', (new ActorIdBlameIdResolver())->resolveBlameId($profile, new AuditActor('u-1')));
        $this->assertNull((new ActorIdBlameIdResolver())->resolveBlameId($profile, new AuditActor(null, 'default')));
        $this->assertNull((new ActorIdBlameIdResolver())->resolveBlameId($profile, null));
    }
}
