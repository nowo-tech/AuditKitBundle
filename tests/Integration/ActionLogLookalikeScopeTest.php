<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Nowo\AuditKitBundle\Tests\Support\LookalikeCollation;
use Nowo\AuditKitBundle\Tests\Support\ScopeExactMatchTestCase;

use function preg_replace;

/**
 * {@see ScopeExactMatchTestCase} on in-memory SQLite (every job): the `scope` column gets {@see LookalikeCollation},
 * which folds like the default MySQL / MariaDB collations, so SQL alone returns the look-alike scopes.
 */
final class ActionLogLookalikeScopeTest extends ScopeExactMatchTestCase
{
    protected function databaseUrl(): ?string
    {
        return null;
    }

    protected function afterSchema(): void
    {
        LookalikeCollation::register($this->connection);
        $ddl = $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$this->table]);
        self::assertIsString($ddl);
        $ddl = preg_replace('/\bscope VARCHAR\(64\)/', 'scope VARCHAR(64) COLLATE ' . LookalikeCollation::NAME, $ddl, -1, $replaced);
        self::assertIsString($ddl);
        self::assertSame(1, $replaced);
        $this->connection->executeStatement('DROP TABLE ' . $this->table);
        $this->connection->executeStatement($ddl);
    }

    protected function assertSqlMatches(int $exact, int $folded): void
    {
        // Guard: SQL alone matches every look-alike, so the assertions test the repository, not SQLite.
        self::assertSame($exact + $folded, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->table . " WHERE scope = 'tenant-a'"));
    }
}
