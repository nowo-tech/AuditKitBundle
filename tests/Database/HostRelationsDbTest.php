<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use Nowo\AuditKitBundle\Tests\Support\HostRelationsTestCase;

use function getenv;
use function is_string;

/** {@see HostRelationsTestCase} on a real database (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; CI job "Databases"). */
final class HostRelationsDbTest extends HostRelationsTestCase
{
    protected function databaseUrl(): string
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }

        return $url;
    }
}
