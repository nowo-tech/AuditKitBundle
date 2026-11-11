<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Security;

use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\SecurityUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class SecurityCurrentActorProviderTest extends TestCase
{
    private const UUID = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';

    public function testNoUserIsNoActor(): void
    {
        $this->assertNull($this->provider(null)->currentActor());
    }

    public function testMatchingProfileGivesItsNameAndTheUserId(): void
    {
        $actor = $this->provider(new SecurityUser(Uuid::fromString(self::UUID), 'alice@example.com'))->currentActor();

        $this->assertEquals(new AuditActor(self::UUID, 'staff'), $actor);
    }

    public function testUserOfNoProfileFallsBackToTheDefaultProfileRules(): void
    {
        // Not a KernelUser (default profile user_class): no profile name, no id.
        $actor = $this->provider(new InMemoryUser('bob@example.com', null), defaultClass: KernelUser::class)->currentActor();
        $this->assertEquals(new AuditActor(), $actor);

        // InMemoryUser matches the default profile (UserInterface) but has no getId(): never its identifier.
        $actor = $this->provider(new InMemoryUser('bob@example.com', null))->currentActor();
        $this->assertEquals(new AuditActor(null, 'default'), $actor);
    }

    /** @param class-string $defaultClass */
    private function provider(?UserInterface $user, string $defaultClass = UserInterface::class): SecurityCurrentActorProvider
    {
        $current = $this->createStub(CurrentUserResolverInterface::class);
        $current->method('resolve')->willReturn($user);

        $profile  = ['enabled' => true, 'fields' => ProfileRegistryFactory::defaultFields(), 'timestamp_type' => 'datetime_immutable', 'blameable' => true, 'timestampable' => true];
        $registry = ProfileRegistryFactory::fromProfiles([
            'default' => ['user_class' => $defaultClass] + $profile,
            'staff'   => ['user_class' => SecurityUser::class] + $profile,
        ]);

        return new SecurityCurrentActorProvider($current, $registry);
    }
}
