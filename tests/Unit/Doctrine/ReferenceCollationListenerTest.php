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
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\Doctrine\ReferenceCollationListener;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\Invoice;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function is_string;

/**
 * REQ-SEC-006 items 3 and 8: binary NO PAD collation on `actor_id` / `subject_id` of an id-mode action log.
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

    public function testTheMappedIdColumnsGetTheCollation(): void
    {
        $args = $this->args(AuditEntry::class);
        (new ReferenceCollationListener($this->registry(new MariaDBPlatform())))->postGenerateSchemaTable($args);

        $table = $args->getClassTable();
        self::assertSame('utf8mb4_nopad_bin', $this->collation($table, 'who_did'));
        self::assertSame('utf8mb4', $this->charset($table, 'who_did'));
        self::assertSame('utf8mb4_nopad_bin', $this->collation($table, 'subject_id'));
        self::assertNull($this->collation($table, 'scope'));
    }

    public function testTheConfiguredServerVersionDecidesOnMysql8(): void
    {
        $args = $this->args(AuditEntry::class);
        (new ReferenceCollationListener($this->registry(new MySQL80Platform(), ['primary' => ['serverVersion' => '8.4.3']])))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_0900_bin', $this->collation($args->getClassTable(), 'who_did'));

        $args = $this->args(AuditEntry::class);
        (new ReferenceCollationListener($this->registry(new MySQL80Platform(), [], '8.0.12')))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_bin', $this->collation($args->getClassTable(), 'subject_id'));
    }

    public function testOtherPlatformsEntitiesAndManagersAreLeftAlone(): void
    {
        foreach ([[AuditEntry::class, new SQLitePlatform()], [AuditEntry::class, new PostgreSQLPlatform()], [Invoice::class, new MariaDBPlatform()]] as [$class, $platform]) {
            $args     = $this->args($class);
            $original = $args->getClassTable();
            (new ReferenceCollationListener($this->registry($platform)))->postGenerateSchemaTable($args);
            self::assertSame($original, $args->getClassTable());
            self::assertNull($this->collation($original, 'who_did'));
        }

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $args = $this->args(AuditEntry::class);
        (new ReferenceCollationListener($registry))->postGenerateSchemaTable($args);
        self::assertNull($this->collation($args->getClassTable(), 'who_did'));

        $args = $this->args(AuditEntry::class, mapped: false);
        (new ReferenceCollationListener($this->registry(new MariaDBPlatform())))->postGenerateSchemaTable($args);
        self::assertNull($this->collation($args->getClassTable(), 'who_did'));
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
            (new ReferenceCollationListener($this->registry($platform, $version === null ? [] : ['serverVersion' => $version], $version === null ? '8.4.3' : null), $logger))
                ->postGenerateSchemaTable($this->args(AuditEntry::class));
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
     * @param class-string $class
     */
    private function args(string $class, bool $mapped = true): GenerateSchemaTableEventArgs
    {
        $metadata = new ClassMetadata($class);
        if ($mapped) {
            // A renamed actor column: the listener follows the mapping.
            $metadata->mapField(['fieldName' => 'actorId', 'columnName' => 'who_did', 'type' => 'string', 'length' => 64]);
            $metadata->mapField(['fieldName' => 'subjectId', 'columnName' => 'subject_id', 'type' => 'string', 'length' => 64]);
        }
        $table = new Table('t');
        foreach (['who_did', 'subject_id', 'scope'] as $column) {
            $table->addColumn($column, 'string', ['length' => 64]);
        }

        return new GenerateSchemaTableEventArgs($metadata, new Schema(), $table);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function registry(AbstractPlatform $platform, array $params = [], ?string $reported = null): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        $connection->method('getServerVersion')->willReturn($reported ?? '8.4.3');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
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
