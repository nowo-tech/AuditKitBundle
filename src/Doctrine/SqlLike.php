<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

/**
 * Escapes user input for SQL / DQL `LIKE` patterns.
 *
 * Always pair the escaped value with {@see self::ESCAPE_CLAUSE}: MySQL defaults to `\` as escape character but
 * SQLite has none, so `x LIKE :q ESCAPE '\'` is the only form that matches literal `%` / `_` on both.
 *
 * ```php
 * $qb->andWhere('e.name LIKE :q ' . SqlLike::ESCAPE_CLAUSE)->setParameter('q', SqlLike::contains($term));
 * ```
 */
final class SqlLike
{
    /** Renders as `ESCAPE '\'`. */
    public const ESCAPE_CLAUSE = "ESCAPE '\\'";

    /** Escapes `\`, `%` and `_` with a backslash. */
    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** `%<escaped value>%` (substring match). */
    public static function contains(string $value): string
    {
        return '%' . self::escape($value) . '%';
    }
}
