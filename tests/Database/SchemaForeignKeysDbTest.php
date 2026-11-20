<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\Doctrine\ReferenceCollationListener;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\FkActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\Owner\FkOwner;
use Nowo\AuditKitBundle\Tests\Support\SchemaShape;
use PHPUnit\Framework\TestCase;

use function getenv;
use function is_string;
use function method_exists;

/**
 * Audit XI-6 (1.18.1) on a real database (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, DBAL 4 and 3; CI job "Databases"):
 * the tables created with the collation listener for a host action log that adds a ManyToOne, a OneToOne and a
 * ManyToMany have every foreign key of the schema generated without it, the id columns have the exact collation on
 * MySQL / MariaDB, and deleting the owner cascades to its log rows. 1.15.1–1.18.0 lost both to-one foreign keys on MySQL
 * / MariaDB with DBAL 4.5+ and ORM 3.7–3.8.
 */
final class SchemaForeignKeysDbTest extends TestCase
{
    private ?EntityManagerInterface $em = null;

    protected function tearDown(): void
    {
        $this->em?->getConnection()->close();
        $this->em = null;
    }

    public function testTheHostAssociationsKeepTheirForeignKeys(): void
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }
        $params   = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $this->em = $em = SchemaShape::entityManager($params, [new ReferenceCollationListener()]);
        $classes  = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);

        /** @var array<non-empty-string, array{fks: list<string>, indexes: list<string>}> $expected */
        $expected      = SchemaShape::of(SchemaShape::generate(SchemaShape::entityManager($params)));
        $connection    = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        foreach ($expected as $name => $shape) {
            // @phpstan-ignore function.alreadyNarrowedType (introspectTableByUnquotedName() exists from DBAL 4.3; DBAL 3 has introspectTable())
            $table = method_exists($schemaManager, 'introspectTableByUnquotedName') ? $schemaManager->introspectTableByUnquotedName($name) : $schemaManager->introspectTable($name);
            self::assertSame($shape['fks'], SchemaShape::of(new Schema([$table]))[$name]['fks'], $name);
        }
        self::assertContains('owner_id->xi_fk_owner(id) CASCADE', $expected['xi_fk_action_log']['fks']);
        self::assertContains('reviewer_id->xi_fk_owner(id) SET NULL', $expected['xi_fk_action_log']['fks']);

        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $version = $params['serverVersion'] ?? null;
            foreach (['actor_id', 'subject_id'] as $column) {
                self::assertSame(
                    ReferenceCollationListener::collationFor($connection->getDatabasePlatform(), is_string($version) ? $version : null),
                    $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'xi_fk_action_log' AND COLUMN_NAME = ?", [$column]),
                    $column,
                );
            }
        }

        $owner = new FkOwner('alice');
        $em->persist($owner);
        $log           = new FkActionLog();
        $log->owner    = $owner;
        $log->reviewer = $owner;
        $em->persist($log);
        $em->flush();
        $connection->executeStatement('DELETE FROM xi_fk_action_log_watcher');
        $connection->executeStatement('DELETE FROM xi_fk_owner WHERE id = ?', [$owner->id]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM xi_fk_action_log'), 'ON DELETE CASCADE removed the owner\'s log rows.');
    }
}
