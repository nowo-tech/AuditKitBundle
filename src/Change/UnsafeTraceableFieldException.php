<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Change;

use InvalidArgumentException;

use function sprintf;

/**
 * Since 2.2: a change policy declares a field whose name looks like a secret (`password`, `token`, `dsn`, …) as
 * `plain`, which would store its value in the action log. Declare it `secret` (only "changed" is stored) or, when the
 * name only looks like a secret, `public`. The message names the field, never a value.
 *
 * @since 2.2.0
 */
final class UnsafeTraceableFieldException extends InvalidArgumentException
{
    public static function forField(string $field): self
    {
        return new self(sprintf('The change field "%s" looks like a secret but its policy is "%s": declare it "%s" (or "%s" when it is not a secret).', $field, ChangeSet::PLAIN, ChangeSet::SECRET, ChangeSet::PUBLIC));
    }

    public static function unknownPolicy(string $field, string $policy): self
    {
        return new self(sprintf('Unknown change policy "%s" for the field "%s" (expected "%s", "%s", "%s" or "%s").', $policy, $field, ChangeSet::PLAIN, ChangeSet::PUBLIC, ChangeSet::SECRET, ChangeSet::LONG_TEXT));
    }
}
