<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Collation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\Doctrine\ReferenceCollationListener;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;
use Throwable;

use function is_a;

/**
 * Declares `actor_id` / `subject_id` of every id-mode action log as identity keys (REQ-SEC-006).
 *
 * This bundle owns no table: {@see AbstractIdActionLog} is a mapped superclass, so the **host** names the table and
 * may rename the columns. The declaration is therefore built from the Doctrine mapping, exactly as
 * {@see ReferenceCollationListener} builds the collation it applies — never from a literal. Whatever the host called
 * its log table, and whatever it called the two columns, the declaration names those.
 *
 * In entity blame mode ({@see AbstractActionLog}) the actor and subject are relations
 * to the host's user entity, not strings: there is no collation question and nothing is declared.
 *
 * Reads mapping metadata only, never the database, and builds the same list every time (worker mode,
 * REQ-RUNTIME-002). Registered only when MigrationsKitBundle is installed (optional dependency).
 */
final readonly class ActionLogExactKeyColumns implements ExactKeyColumnsInterface
{
    public const OWNER = 'nowo-tech/audit-kit-bundle';

    /**
     * What each id-mode field holds, for the report.
     *
     * @var array<ReferenceCollationListener::FIELDS[int], string>
     */
    private const PURPOSE = [
        'actorId'   => 'id of the user who acted: looked up with = when a log is filtered by actor',
        'subjectId' => 'id of the user the action was about: the key of a subject access export / erasure',
    ];

    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $columns = [];
        foreach ($this->doctrine->getManagers() as $manager) {
            if (!$manager instanceof EntityManagerInterface) {
                continue;
            }

            try {
                $all = $manager->getMetadataFactory()->getAllMetadata();
            } catch (Throwable) {
                // A host mapping this bundle cannot load is not this check's business; the host's own tooling reports it.
                continue;
            }

            foreach ($all as $metadata) {
                if (is_a($metadata->getName(), AbstractIdActionLog::class, true)) {
                    $columns = [...$columns, ...$this->columnsOf($metadata)];
                }
            }
        }

        return $columns;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<ExactKeyColumn>
     */
    private function columnsOf(ClassMetadata $metadata): array
    {
        $table   = $metadata->getTableName();
        $columns = [];
        foreach (ReferenceCollationListener::FIELDS as $field) {
            if (!$metadata->hasField($field)) {
                continue;
            }
            $column = $metadata->getColumnName($field);
            if ($column !== '') {
                $columns[] = new ExactKeyColumn($table, $column, self::OWNER, self::PURPOSE[$field]);
            }
        }

        return $columns;
    }
}
