<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;
use Nowo\AuditKitBundle\Avatar\NullActionLogAvatarResolver;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class AuditAvatarKeyTest extends TestCase
{
    public function testTheKeyIsTheIdAsAString(): void
    {
        $user     = new KernelUser('a@example.com');
        $user->id = 7;
        $this->assertSame('7', AuditAvatarKey::of($user));

        $this->assertSame('u-1', AuditAvatarKey::of(new class {
            public function getId(): string
            {
                return 'u-1';
            }
        }));
        $this->assertSame('0b9b', AuditAvatarKey::of(new class {
            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return '0b9b';
                    }
                };
            }
        }));
    }

    public function testUsersWithoutAnIdHaveNoKey(): void
    {
        $this->assertNull(AuditAvatarKey::of(new KernelUser('new@example.com')));
        $this->assertNull(AuditAvatarKey::of(new stdClass()));
        $this->assertNull(AuditAvatarKey::of(new class {
            public string $id = '';
        }));
        $this->assertNull(AuditAvatarKey::of(new class {
            /** @var list<int> */
            public array $id = [1];
        }));
        $this->assertNull(AuditAvatarKey::of(new class {
            private int $id = 3;

            public function number(): int
            {
                return $this->id;
            }
        }), 'a private id property is not read');
    }

    public function testTheNullResolverReturnsNoPicture(): void
    {
        $resolver = new NullActionLogAvatarResolver();
        $this->assertNull($resolver->urlFor(new stdClass(), 24));
        $this->assertSame([], $resolver->urlsFor([new stdClass()], 24));
    }
}
