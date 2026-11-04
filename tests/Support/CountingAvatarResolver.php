<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use LogicException;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;
use RuntimeException;

/**
 * Test double: records every urlsFor() call (users and size), fails on urlFor() (the bundle must batch), and maps
 * user keys to the configured URLs (a missing key gives null, e.g. an anonymised account).
 */
final class CountingAvatarResolver implements ActionLogAvatarResolverInterface
{
    /** @var list<array{keys: list<string|null>, size: int}> */
    public array $calls = [];

    /**
     * @param array<array-key, string|null> $urls key => URL (numeric string keys become ints in PHP)
     */
    public function __construct(private readonly array $urls = [], private readonly bool $throw = false)
    {
    }

    public function urlFor(object $user, int $size): ?string
    {
        throw new LogicException('The bundle must batch through urlsFor().');
    }

    public function urlsFor(array $users, int $size): array
    {
        $this->calls[] = ['keys' => array_map(AuditAvatarKey::of(...), $users), 'size' => $size];
        if ($this->throw) {
            throw new RuntimeException('picture service down');
        }

        return $this->urls + ['unknown-key' => '/ignored'];
    }
}
