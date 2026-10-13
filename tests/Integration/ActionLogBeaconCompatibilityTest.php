<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Tests\Fixtures\Beacon\Adopted\User as AdoptedUser;
use Nowo\AuditKitBundle\Tests\Fixtures\Beacon\Adopted\UserAction as AdoptedUserAction;
use Nowo\AuditKitBundle\Tests\Fixtures\Beacon\LegacyActionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const CASE_LOWER;

/**
 * FR-040: Beacon's `user_action` table (migrations Version20260721140000 + Version20261012120000) must map onto
 * {@see AbstractActionLog} with no schema change (`doctrine:schema:update --dump-sql`
 * empty). Compares the schema generated from a copy of the current Beacon entity with the adopted subclass.
 */
final class ActionLogBeaconCompatibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, class-string<AbstractPlatform>}>
     */
    public static function platformProvider(): iterable
    {
        yield 'sqlite (Beacon tests)' => [['driver' => 'pdo_sqlite', 'memory' => true], SQLitePlatform::class];
        yield 'mysql 8 (Beacon prod, no connection)' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.0.40'], MySQLPlatform::class];
    }

    /**
     * @param array<string, mixed> $params
     * @param class-string<AbstractPlatform> $platformClass
     */
    #[DataProvider('platformProvider')]
    public function testAdoptedMappingProducesNoSchemaDiff(array $params, string $platformClass): void
    {
        $legacy  = $this->schema($params, [__DIR__ . '/../Fixtures/Beacon/Legacy'], false);
        $adopted = $this->schema($params, [__DIR__ . '/../Fixtures/Beacon/Adopted', __DIR__ . '/../../src/ActionLog'], true);

        $platform = DriverManager::getConnection($params)->getDatabasePlatform();
        $this->assertInstanceOf($platformClass, $platform);

        $diff = (new Comparator($platform))->compareSchemas($legacy, $adopted);
        $this->assertSame([], $platform->getAlterSchemaSQL($diff));
        $this->assertSame([], $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($adopted, $legacy)));

        $table = $adopted->getTable('user_action');
        $this->assertSame(
            ['action', 'actor_id', 'context', 'created_at', 'id', 'ip_address', 'project_uuid', 'subject_user_id'],
            $this->sortedColumnNames($table->getColumns()),
        );
        foreach (['idx_user_action_subject_created', 'idx_user_action_actor_created', 'idx_user_action_created', 'idx_user_action_project_created'] as $index) {
            $this->assertTrue($table->hasIndex($index), $index);
        }
        foreach ($table->getForeignKeys() as $foreignKey) {
            $this->assertSame('SET NULL', $foreignKey->getOption('onDelete'));
        }
    }

    public function testAdoptedEntityKeepsHostEnumAccessor(): void
    {
        $entry = (new AdoptedUserAction())->setAction(LegacyActionType::ProjectOpened);

        $this->assertSame('project.opened', $entry->getAction());
        $this->assertSame(LegacyActionType::ProjectOpened, $entry->getActionType());
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $paths
     */
    private function schema(array $params, array $paths, bool $resolveUser): Schema
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver($paths));
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-audit-kit-beacon-proxies');
        $config->setProxyNamespace('NowoAuditKitBeaconProxies');

        $eventManager = new EventManager();
        if ($resolveUser) {
            $listener = new ResolveTargetEntityListener();
            $listener->addResolveTargetEntity(AuditUserInterface::class, AdoptedUser::class, []);
            $eventManager->addEventSubscriber($listener);
        }

        $em = new EntityManager(DriverManager::getConnection($params), $config, $eventManager);

        $metadata = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            if (!$classMetadata->isMappedSuperclass) {
                $metadata[] = $classMetadata;
            }
        }

        return (new SchemaTool($em))->getSchemaFromMetadata($metadata);
    }

    /**
     * @param iterable<Column> $columns
     *
     * @return list<string>
     */
    private function sortedColumnNames(iterable $columns): array
    {
        $names = [];
        foreach ($columns as $column) {
            $names[] = $column->getName();
        }
        sort($names);

        return $names;
    }
}
