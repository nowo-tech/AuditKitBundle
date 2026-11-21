<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\AuditKitBundle\Tests\Support\ScopeExactMatchTestCase;

use function getenv;
use function is_string;

/**
 * {@see ScopeExactMatchTestCase} on a real database (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; CI job "Databases"), with
 * the `scope` column in the collation the schema tool gives it (case- and accent-insensitive on MySQL / MariaDB, PAD
 * SPACE on MariaDB; exact on PostgreSQL).
 */
final class ScopeExactMatchDbTest extends ScopeExactMatchTestCase
{
    protected function databaseUrl(): string
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }

        return $url;
    }

    protected function assertSqlMatches(int $exact, int $folded): void
    {
        $sqlMatches = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->table . " WHERE scope = 'tenant-a'");
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame($exact, $sqlMatches, 'PostgreSQL compares exactly');

            return;
        }

        // Guard: the case variants at least fold to `tenant-a`, so the assertions test the repository.
        self::assertGreaterThan($exact + 1, $sqlMatches);
    }
}
