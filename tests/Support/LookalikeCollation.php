<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use LogicException;
use PDO;

use function is_object;
use function method_exists;
use function preg_replace;
use function rtrim;
use function strcmp;
use function strtolower;
use function strtr;

/**
 * Test helper (REQ-SEC-006): an SQLite collation `LOOKALIKE` that folds like the default MySQL / MariaDB ones
 * (`utf8mb4_0900_ai_ci`, `utf8mb4_uca1400_ai_ci` with PAD SPACE): case, a few accents, control and format characters
 * (ignorable in UCA), Unicode spaces and trailing spaces. `Bob`, `bób`, `bob `, "bob\n", "bob\u{A0}" and
 * "bo\u{200B}b" all compare equal to `bob`, so the look-alike tests run on SQLite in every job.
 */
final class LookalikeCollation
{
    public const NAME = 'LOOKALIKE';

    public static function fold(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? $value;
        $value = preg_replace('/\p{Z}/u', ' ', $value) ?? $value;
        $value = strtr($value, ["\u{00F3}" => 'o', "\u{00D3}" => 'o', "\u{00E9}" => 'e', "\u{00E1}" => 'a']);

        return rtrim(strtolower($value), ' ');
    }

    /** Registers the collation on the native SQLite connection of `$connection`. */
    public static function register(Connection $connection): void
    {
        $native  = $connection->getNativeConnection();
        $compare = static fn (string $a, string $b): int => strcmp(self::fold($a), self::fold($b));
        if (!is_object($native)) {
            throw new LogicException('No native SQLite connection.');
        }
        if (method_exists($native, 'createCollation')) {
            $native->createCollation(self::NAME, $compare); // Pdo\Sqlite (PHP 8.4+)

            return;
        }
        if ($native instanceof PDO) {
            $native->sqliteCreateCollation(self::NAME, $compare);

            return;
        }

        throw new LogicException('The native connection cannot register an SQLite collation.');
    }
}
