<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

/**
 * Since 1.11: the avatar URL policy of the action log timeline, a copy of UiKit's `Nowo\UiKitBundle\Avatar\AvatarUrlPolicy`
 * (UiKit is only suggested). The only picture URLs the timeline puts in `<img src>`.
 *
 * Accepted:
 * - `https://host/…`: scheme `https` (any case), a non-empty host and no userinfo (`https://good@evil` is rejected);
 * - a same-origin path that starts with exactly one `/` (`/files/<uuid>/s64`).
 *
 * Rejected (null): every other scheme (`http:`, `javascript:`, `vbscript:`, `blob:`, `file:`…), `data:` of any kind
 * (including `data:image/…`), protocol-relative `//host`, any backslash, relative paths, ASCII control characters and
 * whitespace (`[\x00-\x20\x7F]`), the empty string and anything longer than {@see self::MAX_BYTES} bytes.
 *
 * The URL must be stable for the page's life and access-checked when the browser fetches it (no bearer secret): the
 * host owns it, the bundle only filters it. Stateless and pure, safe in long-running workers.
 */
final class AvatarUrlPolicy
{
    public const MAX_BYTES = 2048;

    /**
     * The whole policy as one PCRE (byte mode, case-insensitive): 1 to 2048 bytes without control characters,
     * whitespace or backslash, then a same-origin path (`/` not followed by `/`) or `https://` with a host free of
     * userinfo and an optional port of up to 5 digits. The same pattern as UiKit's copy.
     */
    public const PATTERN = '#^(?=[^\x00-\x20\x7F\\\\]{1,2048}$)(?:/(?!/)|https://[^/?\#@:]+(?::[0-9]{1,5})?(?:[/?\#]|$))#i';

    /**
     * The URL when the policy accepts it, else null (the caller renders the initials).
     */
    public static function sanitize(?string $url): ?string
    {
        return $url !== null && preg_match(self::PATTERN, $url) === 1 ? $url : null;
    }

    /**
     * Whether the policy accepts the URL.
     */
    public static function isAllowed(?string $url): bool
    {
        return self::sanitize($url) !== null;
    }
}
