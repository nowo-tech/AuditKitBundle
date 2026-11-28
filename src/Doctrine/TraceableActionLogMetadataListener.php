<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogInterface;

use function hash;
use function is_a;
use function is_array;
use function substr;

/**
 * Since 2.2: maps the traceability fields of an action log entity that implements
 * {@see TraceableActionLogInterface} and does not map them itself:
 *
 * | Field           | Column           | Type                       |
 * |-----------------|------------------|----------------------------|
 * | `correlationId` | `correlation_id` | string 64, nullable        |
 * | `causedBy`      | `caused_by`      | string 64, nullable        |
 * | `changes`       | `changes`        | json, nullable             |
 *
 * plus an index on the `correlation_id` column (`idx_audit_corr_<hash of the table>`) unless the entity already
 * declares an index whose columns start with it. A field the entity maps itself (attribute, XML, override) is left
 * alone, so a host can rename a column. Mapped superclasses and entities that do not implement the interface are
 * untouched: a 2.x host that does not opt in maps no new column.
 *
 * Existing tables need the `ALTER TABLE` of UPGRADING (or `doctrine:migrations:diff`). Stateless.
 *
 * @since 2.2.0
 *
 * @internal registered by the bundle
 */
final readonly class TraceableActionLogMetadataListener
{
    /** field => [column, type, length] */
    public const FIELDS = [
        'correlationId' => ['correlation_id', 'string', TraceableActionLogInterface::CORRELATION_LENGTH],
        'causedBy'      => ['caused_by', 'string', TraceableActionLogInterface::CORRELATION_LENGTH],
        'changes'       => ['changes', 'json', null],
    ];

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->isMappedSuperclass || !is_a($metadata->getName(), TraceableActionLogInterface::class, true)) {
            return;
        }

        foreach (self::FIELDS as $field => [$column, $type, $length]) {
            if ($metadata->hasField($field)) {
                continue;
            }

            $mapping = ['fieldName' => $field, 'columnName' => $column, 'type' => $type, 'nullable' => true];
            if ($length !== null) {
                $mapping['length'] = $length;
            }
            $metadata->mapField($mapping);
        }

        $this->addCorrelationIndex($metadata);
    }

    /** The index name the listener gives the `correlation_id` column of `$table` (short and stable). */
    public static function indexName(string $table): string
    {
        return 'idx_audit_corr_' . substr(hash('sha256', $table), 0, 12);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function addCorrelationIndex(ClassMetadata $metadata): void
    {
        // Single table inheritance: the root entity owns the table and its indexes.
        if ($metadata->getName() !== $metadata->rootEntityName) {
            return;
        }

        $column  = $metadata->getColumnName('correlationId');
        $indexes = is_array($metadata->table['indexes'] ?? null) ? $metadata->table['indexes'] : [];
        foreach ($indexes as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return;
            }
        }
        $indexes[self::indexName($metadata->getTableName())] = ['columns' => [$column]];
        $metadata->table['indexes']                          = $indexes;
    }
}
