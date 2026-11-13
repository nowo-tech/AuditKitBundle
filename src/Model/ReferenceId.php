<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\AbstractUid;

use function array_key_exists;
use function get_object_vars;
use function is_int;
use function is_object;
use function is_string;
use function mb_strlen;
use function method_exists;
use function preg_match;

/**
 * Normalises a user reference to the string stored in an id column (since 1.14).
 *
 * Accepted: a positive int; a string other than `''` and `'0'`; a Symfony `Uuid` / `Ulid`; an object whose `getId()`
 * (else public `id` property) returns one of those or a `Stringable` id value object. Anything else is `null` (not
 * referenced): an object's own `__toString()` is never used (a security user such as `InMemoryUser` returns its
 * identifier, often an e-mail address), so no name or e-mail address ends up in an id column. To store a value object
 * id, pass its string or expose it through `getId()`.
 *
 * Since 1.15.1 (REQ-SEC-006, look-alike ids) a string id must also be usable ({@see self::isUsable()}): valid UTF-8,
 * at most {@see self::MAX_LENGTH} characters, no leading or trailing whitespace (Unicode spaces such as NBSP
 * included) and no control (Cc), format (Cf: zero-width space, BOM, bidi marks), line or paragraph separator
 * character anywhere. Such a value is refused (`null`), never trimmed: on a PAD SPACE collation `bob ` would match
 * `bob`, and invisible characters make look-alikes. Case, accents and Unicode normalisation forms stay significant:
 * `Bob`, `bób` and `bob` are three different ids.
 */
final class ReferenceId
{
    /** Longest usable id, in characters (the default `actor_id` / `subject_id` column holds 64). */
    public const MAX_LENGTH = 255;

    public static function of(mixed $reference): ?string
    {
        if (is_int($reference)) {
            return $reference > 0 ? (string) $reference : null;
        }

        if (is_string($reference)) {
            return $reference !== '0' && self::isUsable($reference) ? $reference : null;
        }

        if ($reference instanceof AbstractUid) {
            return (string) $reference;
        }

        if (!is_object($reference)) {
            return null;
        }

        // Only the explicit id (same lookup as AuditAvatarKey), never the object's __toString().
        if (method_exists($reference, 'getId') || array_key_exists('id', get_object_vars($reference))) {
            $id = method_exists($reference, 'getId') ? $reference->getId() : get_object_vars($reference)['id'];

            return self::idValue($id);
        }

        return null;
    }

    /**
     * Whether `$value` can be a stored id: 1 to {@see self::MAX_LENGTH} characters of valid UTF-8, no leading or
     * trailing whitespace (Unicode included), no control, format, line or paragraph separator character anywhere.
     */
    public static function isUsable(string $value): bool
    {
        return $value !== ''
            && preg_match('/\A(?![\s\p{Z}])[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*(?<![\s\p{Z}])\z/u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= self::MAX_LENGTH;
    }

    /** The value of an explicit id: a scalar, a Uid, or a Stringable id value object (not a user, not an entity). */
    private static function idValue(mixed $id): ?string
    {
        if (!is_object($id) || $id instanceof AbstractUid) {
            return self::of($id);
        }

        if (!$id instanceof Stringable || $id instanceof UserInterface || method_exists($id, 'getId')) {
            return null;
        }

        return self::of((string) $id);
    }
}
