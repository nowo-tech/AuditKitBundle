<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Security;

use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class CurrentUserResolverExtendedTest extends TestCase
{
    public function testReturnsNullWhenTokenUserIsNull(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $storage = new TokenStorage();
        $storage->setToken($token);

        $this->assertNull((new CurrentUserResolver($storage))->resolve());
    }
}
