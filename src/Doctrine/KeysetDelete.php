<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;

use function array_is_list;
use function array_key_exists;
use function count;
use function is_int;
use function is_string;

/**
 * Deletes the rows of one table that match a `WHERE` clause in short primary-key batches (DBAL, no unit of work).
 *
 * Each batch is two short statements in autocommit: `SELECT <id> … WHERE (<where>) AND <id> > :last ORDER BY <id>`
 * with the platform limit, then `DELETE … WHERE <id> IN (<ids>)`. The keyset (`<id> > last id seen`) means rows the
 * clause matches but the delete does not remove are never rescanned, and no statement holds locks / undo for the
 * whole set (unlike one `DELETE … WHERE …` over millions of rows). It stops after the first short batch.
 *
 * `$where` may use positional (`?`) or named (`:name`) placeholders, including array parameters
 * (`ArrayParameterType`); express joins as `col IN (SELECT …)` / `EXISTS (…)`. An empty `$where` deletes every row.
 *
 * `$table`, `$where` and `$idColumn` are SQL fragments inserted as is: quote reserved identifiers yourself and never
 * pass user input there (values go in `$params`).
 *
 * ```php
 * KeysetDelete::run($connection, 'event', 'project_id = ? AND received_at < ?', [$projectId, $cutoff], [
 *     ParameterType::INTEGER, Types::DATETIME_IMMUTABLE,
 * ], batchSize: 2000);
 * ```
 */
final class KeysetDelete
{
    public const DEFAULT_BATCH_SIZE = 1000;

    /** Placeholder name used for the keyset bound when `$params` are named. */
    public const AFTER_ID_PARAMETER = 'keysetDeleteAfterId';

    /**
     * @param array<int|string, mixed> $params values for `$where` (a list for `?`, a map for `:name`)
     * @param array<int<0, max>|string, ArrayParameterType|ParameterType|string|Type> $types DBAL types keyed like
     *                                                                                       `$params` (or type names)
     *
     * @throws InvalidArgumentException when `$params` / `$types` use the reserved name {@see self::AFTER_ID_PARAMETER}
     *
     * @return int number of deleted rows
     */
    public static function run(
        Connection $connection,
        string $table,
        string $where,
        array $params = [],
        array $types = [],
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        string $idColumn = 'id',
    ): int {
        $positional = array_is_list($params);
        if (!$positional && (array_key_exists(self::AFTER_ID_PARAMETER, $params) || array_key_exists(self::AFTER_ID_PARAMETER, $types))) {
            throw new InvalidArgumentException('KeysetDelete: the parameter name "' . self::AFTER_ID_PARAMETER . '" is reserved.');
        }

        $batchSize   = max(1, $batchSize);
        $platform    = $connection->getDatabasePlatform();
        $condition   = trim($where) === '' ? [] : ['(' . $where . ')'];
        $afterIdSlot = $positional ? count($params) : self::AFTER_ID_PARAMETER;
        $orderBy     = ' ORDER BY ' . $idColumn . ' ASC';
        $firstSelect = $platform->modifyLimitQuery(self::select($table, $idColumn, $condition) . $orderBy, $batchSize);
        $nextSelect  = $platform->modifyLimitQuery(
            self::select($table, $idColumn, [...$condition, $idColumn . ' > ' . ($positional ? '?' : ':' . self::AFTER_ID_PARAMETER)]) . $orderBy,
            $batchSize,
        );
        $delete = 'DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' IN (?)';

        $afterId = null;
        $deleted = 0;
        do {
            if ($afterId === null) {
                $rawIds = $connection->fetchFirstColumn($firstSelect, $params, $types);
            } else {
                $nextParams               = $params;
                $nextParams[$afterIdSlot] = $afterId;
                $nextTypes                = $types;
                $nextTypes[$afterIdSlot]  = is_int($afterId) ? ParameterType::INTEGER : ParameterType::STRING;
                $rawIds                   = $connection->fetchFirstColumn($nextSelect, $nextParams, $nextTypes);
            }

            if ($rawIds === []) {
                break;
            }

            [$ids, $integers] = self::normalizeIds($rawIds);
            // Rows come back in id order (database collation, also for string ids): the last one is the keyset bound.
            $afterId = $ids[count($ids) - 1];
            $deleted += (int) $connection->executeStatement(
                $delete,
                [$ids],
                [$integers ? ArrayParameterType::INTEGER : ArrayParameterType::STRING],
            );
        } while (count($rawIds) === $batchSize);

        return $deleted;
    }

    /**
     * @param list<string> $conditions
     */
    private static function select(string $table, string $idColumn, array $conditions): string
    {
        return 'SELECT ' . $idColumn . ' FROM ' . $table . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions));
    }

    /**
     * Integer ids (drivers may return them as strings) become `int`; anything else (UUID, …) stays a string.
     *
     * @param list<mixed> $rawIds
     *
     * @return array{non-empty-list<int|string>, bool} ids and whether all of them are integers
     */
    private static function normalizeIds(array $rawIds): array
    {
        foreach ($rawIds as $id) {
            if (!is_int($id) && (!is_string($id) || preg_match('/^-?\d+$/', $id) !== 1)) {
                /** @var non-empty-list<string> $strings */
                $strings = array_map(static fn (mixed $value): string => (string) $value, $rawIds);

                return [$strings, false];
            }
        }

        /** @var non-empty-list<int> $integers */
        $integers = array_map(static fn (mixed $value): int => (int) $value, $rawIds);

        return [$integers, true];
    }
}
