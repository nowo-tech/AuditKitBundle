<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;

use function array_flip;
use function array_keys;
use function array_map;
use function implode;
use function intdiv;
use function is_bool;
use function max;
use function mb_stripos;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function preg_split;
use function trim;

/**
 * Text search condition for DQL: MySQL / MariaDB `FULLTEXT` (`MATCH … AGAINST` in boolean prefix mode) on the indexed
 * columns, or an escaped `LIKE` ({@see SqlLike}) on other platforms and for queries without a usable word.
 *
 * The FULLTEXT form needs a `MATCH` DQL function registered by the host application, e.g.
 * `DoctrineExtensions\Query\Mysql\MatchAgainst` from `beberlei/doctrineextensions`
 * (`doctrine.orm.dql.string_functions.MATCH`). The bundle does not depend on it.
 *
 * ```php
 * [$dql, $params] = SqlFulltext::clause(['i.title', 'i.culprit'], $term, 'q', $connection);
 * $qb->andWhere($dql);
 * foreach ($params as $name => $value) {
 *     $qb->setParameter($name, $value);
 * }
 * ```
 */
final class SqlFulltext
{
    /** InnoDB's default `innodb_ft_min_token_size`. */
    public const DEFAULT_MIN_TOKEN_LENGTH = 3;

    /** InnoDB's default FULLTEXT stopword list (`INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD`). */
    public const INNODB_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it',
        'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und',
        'www',
    ];

    /** Default width of {@see self::snippet()}, in characters. */
    public const DEFAULT_SNIPPET_WIDTH = 90;

    /**
     * DQL condition matching `$query` on `$fields`, and its parameters.
     *
     * - MySQL / MariaDB with at least one usable word: `MATCH(f1, f2) AGAINST(:param BOOLEAN) > 0` with
     *   {@see self::booleanQuery()} as value. `$fields` must be the columns of one FULLTEXT index, in index order.
     * - Otherwise: `(f1 LIKE :param ESCAPE '\' OR f2 LIKE :param ESCAPE '\')` with `SqlLike::contains(trim($query))`.
     *
     * @param non-empty-list<string> $fields DQL paths (e.g. `i.title`)
     * @param AbstractPlatform|bool|Connection $mysql true / false, or the connection / platform to detect it from
     * @param list<string>|null $stopwords words never required (null = {@see self::INNODB_STOPWORDS})
     *
     * @return array{0: string, 1: array<string, string>} DQL condition and its parameters
     */
    public static function clause(
        array $fields,
        string $query,
        string $param,
        bool|Connection|AbstractPlatform $mysql,
        int $minTokenLength = self::DEFAULT_MIN_TOKEN_LENGTH,
        ?array $stopwords = null,
    ): array {
        $boolean = self::isMysql($mysql) ? self::booleanQuery($query, $minTokenLength, $stopwords) : '';
        if ($boolean !== '') {
            return ['MATCH(' . implode(', ', $fields) . ') AGAINST(:' . $param . ' BOOLEAN) > 0', [$param => $boolean]];
        }

        $likes = array_map(static fn (string $field): string => $field . ' LIKE :' . $param . ' ' . SqlLike::ESCAPE_CLAUSE, $fields);

        return ['(' . implode(' OR ', $likes) . ')', [$param => SqlLike::contains(trim($query))]];
    }

    /**
     * Whether the FULLTEXT form applies: the boolean itself, or a MySQL / MariaDB platform.
     */
    public static function isMysql(bool|Connection|AbstractPlatform $mysql): bool
    {
        if (is_bool($mysql)) {
            return $mysql;
        }
        if ($mysql instanceof Connection) {
            $mysql = $mysql->getDatabasePlatform();
        }

        return $mysql instanceof AbstractMySQLPlatform;
    }

    /**
     * Boolean-mode query requiring every word as a prefix: "fatal err" → "+fatal* +err*".
     *
     * Words are lower-cased and split like the FULLTEXT parser (on anything but letters, digits and `_`:
     * "e2e-xss" → "+e2e* +xss*"); words shorter than `$minTokenLength` (they are not indexed) and stopwords
     * (required, they would match nothing) are dropped, duplicates removed. Returns "" when no word is left.
     *
     * @param list<string>|null $stopwords null = {@see self::INNODB_STOPWORDS}; compared case-insensitively
     */
    public static function booleanQuery(string $query, int $minTokenLength = self::DEFAULT_MIN_TOKEN_LENGTH, ?array $stopwords = null): string
    {
        $minTokenLength = max(1, $minTokenLength);
        $ignored        = array_flip(array_map(mb_strtolower(...), $stopwords ?? self::INNODB_STOPWORDS));
        $parts          = [];
        foreach (preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)) ?: [] as $word) {
            if (mb_strlen($word) >= $minTokenLength && !isset($ignored[$word])) {
                $parts['+' . $word . '*'] = true;
            }
        }

        return implode(' ', array_keys($parts));
    }

    /**
     * About `$width` characters of `$text` (whitespace collapsed) around the first case-insensitive literal match of a
     * query word, with "…" where it was cut; the start of the text when nothing matches literally (stemming, prefixes).
     */
    public static function snippet(string $text, string $query, int $width = self::DEFAULT_SNIPPET_WIDTH): string
    {
        $width = max(1, $width);
        $text  = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $at    = false;
        foreach (preg_split('/\s+/u', trim($query)) ?: [] as $word) {
            if ($word !== '' && false !== ($at = mb_stripos($text, $word))) {
                break;
            }
        }
        $length = mb_strlen($text);
        if ($length <= $width) {
            return $text;
        }
        $start = $at === false ? 0 : max(0, $at - intdiv($width, 3));

        return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, $width)) . ($start + $width < $length ? '…' : '');
    }
}
