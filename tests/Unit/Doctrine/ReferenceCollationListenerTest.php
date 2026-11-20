<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\Doctrine\ReferenceCollationListener;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\FkActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\Owner\FkOwner;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use Nowo\AuditKitBundle\Tests\Support\SchemaShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function dirname;
use function is_string;

/**
 * REQ-SEC-006 items 3 and 8: binary NO PAD collation on `actor_id` / `subject_id` of an id-mode action log. Audit XI-6
 * (1.18.1): applied on postGenerateSchema, so the foreign keys and indexes of a host subclass's own associations are kept.
 */
final class ReferenceCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string|null, string|null}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), '11.4.3-MariaDB', 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.0.17' => [new MySQL80Platform(), '8.0.17', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.4' => [new MySQL80Platform(), '8.4.3', 'utf8mb4_0900_bin'];
        yield 'MySQL 8.0.16' => [new MySQL80Platform(), '8.0.16', 'utf8mb4_bin'];
        yield 'MySQL 8.0 without patch' => [new MySQL80Platform(), '8.0', 'utf8mb4_bin'];
        yield 'MySQL 8 without minor' => [new MySQL80Platform(), '8', 'utf8mb4_bin'];
        yield 'MySQL 8 unknown version' => [new MySQL80Platform(), null, 'utf8mb4_0900_bin'];
        yield 'MySQL 8 unparsable version' => [new MySQL80Platform(), 'latest', 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [new MySQLPlatform(), '5.7.44', 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), '16', null];
        yield 'SQLite' => [new SQLitePlatform(), null, null];
    }

    #[DataProvider('platforms')]
    public function testTheCollationFollowsThePlatformAndServerVersion(AbstractPlatform $platform, ?string $version, ?string $expected): void
    {
        self::assertSame($expected, ReferenceCollationListener::collationFor($platform, $version));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function connections(): iterable
    {
        yield 'MariaDB 11.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin'];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], 'utf8mb4_0900_bin'];
        yield 'MySQL 5.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin'];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null];
    }

    /**
     * XI-6: a host subclass with its own ManyToOne / OneToOne / ManyToMany keeps every foreign key and index.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('connections')]
    public function testTheIdColumnsAreCollatedAndEveryForeignKeyAndIndexIsKept(array $params, ?string $collation): void
    {
        $with    = SchemaShape::entityManager($params, [new ReferenceCollationListener()]);
        $without = SchemaShape::entityManager($params);
        $schema  = SchemaShape::generate($with);
        $shape   = SchemaShape::of($schema);

        self::assertSame(SchemaShape::of(SchemaShape::generate($without)), $shape);
        self::assertContains('owner_id->xi_fk_owner(id) CASCADE', $shape['xi_fk_action_log']['fks']);
        self::assertContains('reviewer_id->xi_fk_owner(id) SET NULL', $shape['xi_fk_action_log']['fks']);
        self::assertContains('owner_id', $shape['xi_fk_action_log']['indexes']);
        self::assertContains('unique reviewer_id', $shape['xi_fk_action_log']['indexes']);
        $table = $schema->getTable('xi_fk_action_log');
        self::assertSame($collation, $this->collation($table, 'actor_id'));
        self::assertSame($collation, $this->collation($table, 'subject_id'));
        self::assertSame($collation === null ? null : 'utf8mb4', $this->charset($table, 'actor_id'));
        self::assertNull($this->collation($table, 'scope'));
    }

    public function testTheConfiguredServerVersionDecidesOnMysql8(): void
    {
        $args = $this->args(new MySQL80Platform(), ['primary' => ['serverVersion' => '8.4.3']]);
        (new ReferenceCollationListener())->postGenerateSchema($args);
        self::assertSame('utf8mb4_0900_bin', $this->collation($args->getSchema()->getTable('xi_fk_action_log'), 'actor_id'));

        $args = $this->args(new MySQL80Platform(), [], '8.0.12');
        (new ReferenceCollationListener())->postGenerateSchema($args);
        self::assertSame('utf8mb4_bin', $this->collation($args->getSchema()->getTable('xi_fk_action_log'), 'subject_id'));
    }

    public function testOtherPlatformsAndManagersAreLeftAlone(): void
    {
        foreach ([new SQLitePlatform(), new PostgreSQLPlatform()] as $platform) {
            $args     = $this->args($platform);
            $original = $args->getSchema();
            (new ReferenceCollationListener())->postGenerateSchema($args);
            self::assertSame($original, $args->getSchema());
            self::assertNull($this->collation($original->getTable('xi_fk_action_log'), 'actor_id'));
        }

        // A manager without an id-mode action log, and one whose generated schema does not hold the log's table.
        $owners = SchemaShape::entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], [new ReferenceCollationListener()], [dirname(__DIR__, 2) . '/Fixtures/SchemaFk/Owner']);
        self::assertSame(['xi_fk_owner'], array_keys(SchemaShape::of(SchemaShape::generate($owners))));
        $logs   = SchemaShape::entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], [new ReferenceCollationListener()]);
        $schema = (new SchemaTool($logs))->getSchemaFromMetadata([$logs->getClassMetadata(FkOwner::class)]);
        self::assertSame(['xi_fk_owner'], array_keys(SchemaShape::of($schema)));
        self::assertFalse($logs->getConnection()->isConnected());
    }

    public function testAMysqlServerVersionWithoutPatchIsWarnedAbout(): void
    {
        /** @var list<array{AbstractPlatform, string|null, int}> $cases */
        $cases = [
            [new MySQL80Platform(), '8.0', 1],
            [new MySQLPlatform(), '8', 1],
            [new MySQL80Platform(), '8.4.3', 0],
            [new MySQL80Platform(), '9.7', 0],
            [new MariaDBPlatform(), '11.4', 0],
            [new MySQLPlatform(), '5.7.44', 0],
            [new MySQL80Platform(), null, 0],
        ];
        foreach ($cases as [$platform, $version, $warnings]) {
            $logger = new RecordingLogger();
            (new ReferenceCollationListener(null, $logger))
                ->postGenerateSchema($this->args($platform, $version === null ? [] : ['serverVersion' => $version], $version === null ? '8.4.3' : null));
            self::assertCount($warnings, $logger->records, (string) $version);
            if ($warnings === 1) {
                self::assertStringContainsString('has no patch number', (string) $logger->records[0][1]);
            }
        }
    }

    public function testOlderDbalCollatesInPlace(): void
    {
        $table = new Table('t');
        $table->addColumn('actor_id', 'string', ['length' => 64]);

        self::assertNull(ReferenceCollationListener::collate($table, 'actor_id', 'utf8mb4_nopad_bin', false));
        self::assertSame('utf8mb4_nopad_bin', $table->getColumn('actor_id')->getPlatformOption('collation'));
        self::assertSame('utf8mb4', $table->getColumn('actor_id')->getPlatformOption('charset'));
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function apiCombinations(): iterable
    {
        yield 'DBAL 4.5, ORM 3.7' => [true, true, true, true];
        yield 'DBAL 4.3/4.4 (no Schema::edit), ORM 3.7' => [true, false, true, false];
        yield 'DBAL 4.5, ORM 3.0-3.6 (no setSchema)' => [true, true, false, false];
        yield 'DBAL 3.x / 4.0-4.2, ORM 2.x' => [false, false, false, false];
    }

    #[DataProvider('apiCombinations')]
    public function testTheEditorPathNeedsEveryApi(bool $tableEdit, bool $schemaEdit, bool $setSchema, bool $expected): void
    {
        self::assertSame($expected, ReferenceCollationListener::editorSupported($tableEdit, $schemaEdit, $setSchema));
    }

    public function testNothingToCollateKeepsTheSchema(): void
    {
        $args   = $this->args(new MariaDBPlatform());
        $schema = $args->getSchema();
        ReferenceCollationListener::collateSchema($args, ['xi_fk_action_log' => ['missing']], 'utf8mb4_nopad_bin');
        self::assertSame($schema, $args->getSchema());
    }

    public function testTheFormerTableEventDoesNothing(): void
    {
        $table = new Table('t');
        $table->addColumn('actor_id', 'string', ['length' => 64]);
        $args = new GenerateSchemaTableEventArgs(new ClassMetadata(FkActionLog::class), new Schema(), $table);

        (new ReferenceCollationListener())->postGenerateSchemaTable($args);

        self::assertSame($table, $args->getClassTable());
        self::assertNull($this->collation($table, 'actor_id'));
    }

    /**
     * The schema of the XI-6 fixtures, given to the listener with an entity manager on `$platform`.
     *
     * @param array<string, mixed> $params
     */
    private function args(AbstractPlatform $platform, array $params = [], ?string $reported = null): GenerateSchemaEventArgs
    {
        $real       = SchemaShape::entityManager(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = SchemaShape::generate($real);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        $connection->method('getServerVersion')->willReturn($reported ?? '8.4.3');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getMetadataFactory')->willReturn($real->getMetadataFactory());

        return new GenerateSchemaEventArgs($manager, $schema);
    }

    private function collation(Table $table, string $column): ?string
    {
        $value = $table->getColumn($column)->getPlatformOptions()['collation'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function charset(Table $table, string $column): ?string
    {
        $value = $table->getColumn($column)->getPlatformOptions()['charset'] ?? null;

        return is_string($value) ? $value : null;
    }
}
