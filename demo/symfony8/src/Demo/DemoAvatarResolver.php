<?php

declare(strict_types=1);

namespace App\Demo;

use Nowo\AuditKitBundle\Avatar\ActionLogAvatarResolverInterface;
use Nowo\AuditKitBundle\Avatar\AuditAvatarKey;

/**
 * Demo avatar resolver (aliased in config/services.yaml): a same-origin picture, a broken URL, an anonymised account
 * (null) and a URL the policy rejects (http:). Counts its calls so the page can show "one call per timeline".
 */
final class DemoAvatarResolver implements ActionLogAvatarResolverInterface
{
    public const URLS = [
        '1' => '/images/avatar-ada.svg',
        '2' => '/does-not-exist.webp',
        '3' => null,
        '4' => 'http://example.com/turing.png',
    ];

    public int $calls = 0;

    public function urlFor(object $user, int $size): ?string
    {
        return self::URLS[AuditAvatarKey::of($user) ?? ''] ?? null;
    }

    public function urlsFor(array $users, int $size): array
    {
        ++$this->calls;
        $urls = [];
        foreach ($users as $user) {
            $key = AuditAvatarKey::of($user);
            if (null !== $key) {
                $urls[$key] = self::URLS[$key] ?? null;
            }
        }

        return $urls;
    }
}
