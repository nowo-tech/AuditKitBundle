<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

/**
 * Since 1.11: the URL of the profile picture of an action log actor or subject. The application aliases this
 * interface; the default ({@see NullActionLogAvatarResolver}) returns null for everyone, and the timeline then renders
 * exactly as before (no avatar).
 *
 * The URL MUST be stable for at least the life of a page and access-checked when the browser fetches it (no bearer
 * secret in it). The bundle accepts `https:` URLs without userinfo and same-origin paths starting with a single `/`;
 * anything else is dropped ({@see AvatarUrlPolicy}). Return null for anonymised or erased accounts.
 */
interface ActionLogAvatarResolverInterface
{
    /**
     * @param object $user An actor or subject of the action log (the profile `user_class`)
     * @param int $size Largest CSS size in px the picture is displayed at (the host picks a variant, e.g. ≥ 2× for
     *                  HiDPI); resolvers accept any int from 1 to 512
     *
     * @return string|null The URL, or null for no picture
     */
    public function urlFor(object $user, int $size): ?string;

    /**
     * One call per rendered timeline (the bundle never calls urlFor()). Must not run a query per user.
     *
     * @param list<object> $users Distinct users (by {@see AuditAvatarKey::of()}), never empty
     *
     * @return array<array-key, string|null> AuditAvatarKey::of($user) => URL or null (PHP turns numeric keys into
     *                                       ints, which is fine); a missing key means null, unknown keys are ignored
     */
    public function urlsFor(array $users, int $size): array;
}
