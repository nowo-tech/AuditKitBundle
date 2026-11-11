<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use LogicException;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;

/**
 * Test double for a multi-tenant host: the same user id exists in every tenant, and each tenant has its own picture
 * (`/files/<tenant>/<id>/s<size>`). `$tenant` is the host's current tenant, set by the test per unit of work.
 */
final class TenantAvatarResolver implements ActionLogAvatarResolverInterface
{
    public string $tenant = 'none';

    public int $calls = 0;

    public function urlFor(object $user, int $size): ?string
    {
        throw new LogicException('The bundle must batch through urlsFor().');
    }

    public function urlsFor(array $users, int $size): array
    {
        ++$this->calls;
        $urls = [];
        foreach ($users as $user) {
            $key = AuditAvatarKey::of($user);
            if ($key !== null) {
                $urls[$key] = '/files/' . $this->tenant . '/' . $key . '/s' . $size;
            }
        }

        return $urls;
    }
}
