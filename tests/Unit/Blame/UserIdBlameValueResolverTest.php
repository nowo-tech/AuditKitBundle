<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Blame;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Blame\UserIdBlameValueResolver;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\SecurityUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class UserIdBlameValueResolverTest extends TestCase
{
    private const UUID = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';

    public function testReturnsTheUserIdAsAString(): void
    {
        $user = new SecurityUser(Uuid::fromString(self::UUID), 'alice@example.com');

        $this->assertSame(self::UUID, $this->resolver($user)->resolve($this->profile(UserInterface::class), $this->createStub(ObjectManager::class)));
    }

    public function testMatchesTheProfileClassOrInterface(): void
    {
        $user = new SecurityUser(Uuid::fromString(self::UUID), 'alice@example.com');

        $this->assertSame(self::UUID, $this->resolver($user)->resolve($this->profile(SecurityUser::class), $this->createStub(ObjectManager::class)));
        $this->assertNull($this->resolver($user)->resolve($this->profile(KernelUser::class), $this->createStub(ObjectManager::class)));
    }

    public function testNoUserOrNoIdIsNotBlamed(): void
    {
        $this->assertNull($this->resolver(null)->resolve($this->profile(UserInterface::class), $this->createStub(ObjectManager::class)));
        // InMemoryUser has no getId(): its identifier (an e-mail address here) is never used.
        $this->assertNull($this->resolver(new InMemoryUser('bob@example.com', null))->resolve($this->profile(UserInterface::class), $this->createStub(ObjectManager::class)));
    }

    private function resolver(?UserInterface $user): UserIdBlameValueResolver
    {
        $current = $this->createStub(CurrentUserResolverInterface::class);
        $current->method('resolve')->willReturn($user);

        return new UserIdBlameValueResolver($current);
    }

    /** @param class-string $userClass */
    private function profile(string $userClass): ProfileSettings
    {
        return new ProfileSettings('default', $userClass, true, ['created_at' => 'createdAt', 'updated_at' => 'updatedAt', 'created_by' => 'createdBy', 'updated_by' => 'updatedBy'], 'datetime_immutable', true, true);
    }
}
