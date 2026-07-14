<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Security;

use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class CurrentUserResolverTest extends TestCase
{
    public function testReturnsNullWithoutToken(): void
    {
        $storage = new TokenStorage();
        $this->assertNull((new CurrentUserResolver($storage))->resolve());
    }

    public function testReturnsAuthenticatedUser(): void
    {
        $user    = new InMemoryUser('demo', null);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $this->assertSame($user, (new CurrentUserResolver($storage))->resolve());
    }
}
