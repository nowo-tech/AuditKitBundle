<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use Stringable;

use function array_key_exists;
use function get_object_vars;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;

/**
 * Normalises a user reference to the string stored in an id column (since 1.14).
 *
 * Accepted: a positive int; a string other than `''` and `'0'`; an object whose `getId()` (else public `id` property)
 * returns one of those or a `Stringable` id; a `Stringable` id object without an id of its own (Symfony `Uuid` /
 * `Ulid`, value objects). Anything else is `null` (not referenced). An entity's `__toString()` is never used when it
 * has an id, so no name or e-mail address ends up in an id column.
 */
final class ReferenceId
{
    public static function of(mixed $reference): ?string
    {
        if (is_int($reference)) {
            return $reference > 0 ? (string) $reference : null;
        }

        if (is_string($reference)) {
            return $reference !== '' && $reference !== '0' ? $reference : null;
        }

        if (!is_object($reference)) {
            return null;
        }

        // An entity's id wins over its __toString() (often a name or an e-mail address); same lookup as AuditAvatarKey.
        if (method_exists($reference, 'getId') || array_key_exists('id', get_object_vars($reference))) {
            $id = method_exists($reference, 'getId') ? $reference->getId() : get_object_vars($reference)['id'];

            return is_object($id) && (!$id instanceof Stringable || method_exists($id, 'getId')) ? null : self::of($id);
        }

        if ($reference instanceof Stringable) {
            return self::of((string) $reference);
        }

        return null;
    }
}
