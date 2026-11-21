<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

use function array_map;
use function array_values;
use function is_a;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;
use function preg_match;
use function version_compare;

/**
 * Since 1.15.1 (REQ-SEC-006, look-alike ids): binary NO PAD collation on the `actor_id` / `subject_id` columns of an
 * id-mode action log ({@see AbstractIdActionLog} subclass) on MySQL / MariaDB.
 *
 * The default MySQL / MariaDB collations fold case and accents (`Bob` = `bob` = `bób`) and the PAD SPACE ones ignore
 * trailing spaces (`bob ` = `bob`). The repository re-checks every row with `===`; this collation keeps the SQL
 * pre-selection exact as well: MariaDB `utf8mb4_nopad_bin`, MySQL 8.0.17+ `utf8mb4_0900_bin`, older MySQL
 * `utf8mb4_bin` (PAD SPACE, the only binary utf8mb4 collation it has; the re-check covers it). PostgreSQL and SQLite
 * compare exactly and are left alone (a `collation` option is not portable, so it cannot live in the mapping).
 *
 * Applied when the schema is generated from the mapping (`doctrine:migrations:diff`, `doctrine:schema:update`), on the
 * columns the entity maps for `actorId` / `subjectId` (renamed columns included); existing tables need the `ALTER` of
 * UPGRADING. A MySQL `server_version` without its patch number (`8`, `8.0`) cannot select `utf8mb4_0900_bin`: a
 * warning is logged and `utf8mb4_bin` is used.
 *
 * 1.18.1 (audit XI-6): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * 1.15.1–1.18.0 replaced the table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+ with ORM 3.7+), and ORM
 * 3.7–3.8 then added the table's foreign keys to the object it had replaced: on MySQL / MariaDB the foreign keys (and
 * their indexes) of the to-one associations a host's subclass adds were missing from the generated schema. Now:
 *
 * - DBAL 4.5+ with ORM 3.7+: the table is rebuilt with its table editor (foreign keys and indexes included) and swapped
 *   into a new schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - anything older (ORM 2.x, ORM 3.0–3.6, DBAL 3.x–4.4): the column's platform options are set in place.
 *
 * Stateless (REQ-RUNTIME-002): the platform is the one of the schema tool's entity manager, read on each event; a
 * manager that maps no id-mode action log is left alone.
 *
 * @internal
 */
final readonly class ReferenceCollationListener
{
    /** Fallback binary collation (MySQL < 8.0.17); PAD SPACE. */
    public const COLLATION = 'utf8mb4_bin';

    /** MySQL 8.0.17+ binary NO PAD collation. */
    public const MYSQL_NOPAD_COLLATION = 'utf8mb4_0900_bin';

    /** MariaDB binary NO PAD collation. */
    public const MARIADB_NOPAD_COLLATION = 'utf8mb4_nopad_bin';

    /** First MySQL release with `utf8mb4_0900_bin`. */
    public const MYSQL_NOPAD_SINCE = '8.0.17';

    /** Id-mode fields that hold a user id. */
    public const FIELDS = ['actorId', 'subjectId'];

    /**
     * @param ManagerRegistry|null $doctrine unused since 1.18.1 (the platform comes from the schema tool's entity
     *                                       manager); kept for BC
     */
    public function __construct(
        ?ManagerRegistry $doctrine = null,
        private ?LoggerInterface $logger = null,
    ) {
        unset($doctrine);
    }

    /**
     * The exact-match collation for that platform and server version, null when the platform already compares exactly.
     * A MySQL 8 platform with an unknown server version gets `utf8mb4_0900_bin`; a version without a patch number
     * (`8.0`) is read as `8.0.0` and keeps the `utf8mb4_bin` fallback.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => self::MARIADB_NOPAD_COLLATION,
            $platform instanceof MySQL80Platform       => self::supportsMysqlNoPad($serverVersion) ? self::MYSQL_NOPAD_COLLATION : self::COLLATION,
            $platform instanceof AbstractMySQLPlatform => self::COLLATION,
            default                                    => null,
        };
    }

    /**
     * Gives the id columns of every id-mode action log in the generated schema their exact-match collation, keeping every
     * foreign key and index of its table.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $columns = $this->idColumns($manager, $args->getSchema());
        if ($columns === []) {
            return;
        }
        $connection = $manager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $version    = $platform instanceof MySQL80Platform ? $this->serverVersion($connection) : null;
        $collation  = self::collationFor($platform, $version);
        if ($collation === null) {
            return;
        }
        $this->warnAboutPartialVersion($platform, $this->configuredVersion($connection), $collation);
        self::collateSchema($args, $columns, $collation);
    }

    /**
     * Whether the table-editor path is usable: it rebuilds the table (`Table::edit()`, DBAL 4.3+) and swaps it into a new
     * schema (`Schema::edit()`, DBAL 4.5+, and `GenerateSchemaEventArgs::setSchema()`, ORM 3.7+). Anything less uses the
     * in-place path.
     */
    public static function editorSupported(bool $tableEdit, bool $schemaEdit, bool $setSchema): bool
    {
        return $tableEdit && $schemaEdit && $setSchema;
    }

    /**
     * Gives `$columns` (table name => columns) `$collation` in the schema of `$args`, keeping every foreign key and index:
     * rebuilt with the table editor and swapped into a new schema when {@see editorSupported()}, changed in place
     * otherwise.
     *
     * @param array<string, list<non-empty-string>> $columns
     * @param non-empty-string $collation
     */
    public static function collateSchema(GenerateSchemaEventArgs $args, array $columns, string $collation): void
    {
        $schema = $args->getSchema();
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType (older doctrine/dbal / doctrine/orm)
        $editor = self::editorSupported(method_exists(Table::class, 'edit'), method_exists(Schema::class, 'edit'), method_exists(GenerateSchemaEventArgs::class, 'setSchema'));
        $edited = [];
        foreach ($columns as $name => $tableColumns) {
            $original = $table = $schema->getTable($name);
            foreach ($tableColumns as $column) {
                if ($table->hasColumn($column)) {
                    $table = self::collate($table, $column, $collation, $editor) ?? $table;
                }
            }
            if ($table !== $original) {
                $edited[] = [$original, $table];
            }
        }
        if ($edited === []) {
            return;
        }
        $schemaEditor = $schema->edit();
        foreach ($edited as [$original, $table]) {
            $schemaEditor->dropTable($original->getObjectName())->addTable($table);
        }
        $args->setSchema($schemaEditor->create());
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }

    /**
     * The `actorId` / `subjectId` columns (renamed ones included) of every id-mode action log `$manager` maps, per table
     * of `$schema`.
     *
     * @return array<string, list<non-empty-string>> table name => columns
     */
    private function idColumns(EntityManagerInterface $manager, Schema $schema): array
    {
        $columns = [];
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || !is_a($metadata->getName(), AbstractIdActionLog::class, true)) {
                continue;
            }
            $table = $metadata->getTableName();
            if (!$schema->hasTable($table)) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                $column = $metadata->hasField($field) ? $metadata->getColumnName($field) : '';
                if ($column !== '') {
                    $columns[$table][$column] = $column;
                }
            }
        }

        return array_map(array_values(...), $columns);
    }

    /**
     * REQ-SEC-006 item 8: a MySQL server version configured without its patch number (`8`, `8.0`) leaves the id columns
     * on `utf8mb4_bin`, which is PAD SPACE (`'bob '` = `'bob'`).
     */
    private function warnAboutPartialVersion(AbstractPlatform $platform, ?string $version, string $collation): void
    {
        if ($collation !== self::COLLATION || $platform instanceof MariaDBPlatform || $version === null || preg_match('/\A\d+\.\d+\.\d+/', $version) === 1) {
            return;
        }

        $this->logger?->warning('doctrine.dbal.server_version "{version}" has no patch number: the AuditKit action log id columns get the PAD SPACE collation utf8mb4_bin. Configure the full version (e.g. 8.4.3) to get utf8mb4_0900_bin.', ['bundle' => 'NowoAuditKitBundle', 'version' => $version]);
    }

    /**
     * The server version configured on the connection (also on a primary / replica connection), if any.
     */
    private function configuredVersion(Connection $connection): ?string
    {
        $params  = $connection->getParams();
        $primary = $params['primary'] ?? null;
        $version = $params['serverVersion'] ?? (is_array($primary) ? ($primary['serverVersion'] ?? null) : null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The configured server version, else the one the server reports (null when it cannot be read: unknown).
     */
    private function serverVersion(Connection $connection): ?string
    {
        $version = $this->configuredVersion($connection);
        if ($version !== null) {
            return $version;
        }
        // doctrine/dbal 4: public Connection::getServerVersion(); doctrine/dbal 3 keeps it private (the wrapped driver
        // connection reports it).
        if ((new ReflectionMethod($connection, 'getServerVersion'))->isPublic()) {
            return $connection->getServerVersion();
        }

        return $this->wrappedServerVersion($connection); // @codeCoverageIgnore
    }

    /**
     * doctrine/dbal 3 only.
     *
     * @codeCoverageIgnore
     */
    private function wrappedServerVersion(Connection $connection): ?string
    {
        $driver  = method_exists($connection, 'getWrappedConnection') ? $connection->getWrappedConnection() : null;
        $version = is_object($driver) && is_callable([$driver, 'getServerVersion']) ? $driver->getServerVersion() : null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function supportsMysqlNoPad(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/\A(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $serverVersion, $match) !== 1) {
            return true;
        }

        $version = $match[1] . '.' . (($match[2] ?? '') !== '' ? $match[2] : '0') . '.' . (($match[3] ?? '') !== '' ? $match[3] : '0');

        return version_compare($version, self::MYSQL_NOPAD_SINCE, '>=');
    }
}
