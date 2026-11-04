<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

/**
 * Since 1.11: the default {@see ActionLogAvatarResolverInterface}: no picture for anyone, so the timeline renders as
 * before. Override the alias in the application's services to show pictures.
 */
final class NullActionLogAvatarResolver implements ActionLogAvatarResolverInterface
{
    public function urlFor(object $user, int $size): ?string
    {
        return null;
    }

    public function urlsFor(array $users, int $size): array
    {
        return [];
    }
}
