<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

use Stringable;

use function get_object_vars;
use function is_int;
use function is_string;
use function method_exists;

/**
 * Since 1.11: the key of a user in {@see ActionLogAvatarResolverInterface::urlsFor()}: its id as a string (`getId()`,
 * else a public `id` property; an int, a string or a Stringable such as a Uid). A user without an id (never persisted)
 * has no key and never reaches the resolver. Hosts call this helper instead of building the key themselves.
 */
final class AuditAvatarKey
{
    public static function of(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : (get_object_vars($user)['id'] ?? null);
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_string($id) || $id instanceof Stringable) {
            $id = (string) $id;

            return $id === '' ? null : $id;
        }

        return null;
    }
}
