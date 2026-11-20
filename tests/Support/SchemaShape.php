<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;

use function array_map;
use function implode;
use function ksort;
use function sort;
use function str_replace;
use function strtolower;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Audit XI-6 (1.18.1): the foreign keys and indexes of a schema, per table, to compare a schema generated with the
 * collation listeners against one generated without them.
 */
final class SchemaShape
{
    /**
     * An entity manager over the audit XI-6 fixtures (or `$paths`); the connection is opened only to create tables.
     *
     * @param array<string, mixed> $params
     * @param list<object> $listeners postGenerateSchema listeners
     * @param list<string>|null $paths entity directories (null: tests/Fixtures/SchemaFk)
     */
    public static function entityManager(array $params, array $listeners = [], ?array $paths = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration($paths ?? [__DIR__ . '/../Fixtures/SchemaFk'], true, sys_get_temp_dir() . '/nowo-audit-kit-schema-shape-proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $em = new EntityManager(DriverManager::getConnection($params, $config), $config);
        foreach ($listeners as $listener) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, $listener);
        }

        return $em;
    }

    public static function generate(EntityManagerInterface $em): Schema
    {
        return (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and indexes (columns, uniqueness) per table.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    public static function of(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = ($index->isPrimary() ? 'primary ' : ($index->isUnique() ? 'unique ' : '')) . implode(',', array_map(strtolower(...), $index->getColumns()));
            }
            // Introspected names come quoted ("user_id", `user_id`); generated ones do not.
            $fks     = str_replace(['"', '`'], '', $fks);
            $indexes = str_replace(['"', '`'], '', $indexes);
            sort($fks);
            sort($indexes);
            $shape[strtolower(str_replace(['"', '`'], '', $table->getName()))] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }
}
