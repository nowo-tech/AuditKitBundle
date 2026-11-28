<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\Doctrine\KeysetDelete;
use Nowo\AuditKitBundle\Model\ReferenceId;

use function array_slice;
use function count;
use function in_array;
use function max;
use function min;

/**
 * Queries and keyset purge for an {@see AbstractActionLog} or (since 1.14) {@see AbstractIdActionLog} entity.
 *
 * Extend it in the host (`class UserActionRepository extends ActionLogRepository`, constructor calling
 * `parent::__construct($registry, UserAction::class)`) and point the entity `repositoryClass` to the subclass.
 * Every query goes through the entity metadata, so renamed columns (attribute / association overrides) work. With an
 * id-mode entity, `actor` / `subject` / `involving` filters compare the stored ids (entities are turned into their id).
 *
 * Since 1.15.1 (REQ-SEC-006, look-alike ids) id-mode filters match byte for byte: SQL only pre-selects (the column
 * collation may fold case, accents and trailing spaces), every row is re-checked with `===` before paging and
 * counting, and a filter id refused by {@see ReferenceId} matches nothing. Give `actor_id` / `subject_id` a binary NO
 * PAD collation on MySQL / MariaDB (the bundle's listener does it for generated schemas; see UPGRADING) so SQL
 * pre-selects the exact rows only.
 *
 * Since 2.1 it implements {@see ActionLogReaderInterface} (snapshots, keyset paging): hosts can type-hint the port, and
 * no longer need to extend this class to list or count rows.
 *
 * Since 2.1 (audit YC1-M1) the `scope` filter (the tenant / project key) matches byte for byte as well, in both modes:
 * SQL `IN` only pre-selects (a `*_ci` / PAD SPACE collation returns `Tenant-A` and `tenant-a ` for `tenant-a`), and
 * every row is re-checked with `===` before paging and counting.
 *
 * Since 2.2 the `correlationId` filter (entities implementing {@see TraceableActionLogInterface}) matches byte for byte
 * the same way; on an entity without the column it matches nothing. Views carry the traceability columns.
 *
 * @template T of ActionLogEntryInterface
 *
 * @extends ServiceEntityRepository<T>
 */
class ActionLogRepository extends ServiceEntityRepository implements ActionLogReaderInterface
{
    public const DEFAULT_BATCH_SIZE = KeysetDelete::DEFAULT_BATCH_SIZE;

    /** Since 1.15.1: smallest batch of the exact-match loop of {@see findByCriteria()} (id filters, since 2.1 scopes). */
    private const EXACT_BATCH_SIZE = 50;

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(ManagerRegistry $registry, string $entityClass)
    {
        parent::__construct($registry, $entityClass);
    }

    /**
     * Rows matching the criteria, newest first (`createdAt DESC, id DESC`), actor and subject fetch-joined.
     *
     * @return list<T>
     */
    public function findByCriteria(ActionLogCriteria $criteria): array
    {
        if ($criteria->matchesNothing()) {
            return [];
        }

        $references = $this->exactFilter($criteria);
        if ($references === false) {
            return [];
        }

        $qb = $this->createCriteriaQueryBuilder($criteria);
        if ($this->referencesByAssociation()) {
            $qb->leftJoin('a.actor', 'actor')->addSelect('actor')
                ->leftJoin('a.subject', 'subject')->addSelect('subject');
        }

        $qb->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($references === null) {
            $qb->setMaxResults(max(1, $criteria->limit))
                ->setFirstResult(max(0, $criteria->offset));

            /** @var list<T> $rows */
            $rows = $qb->getQuery()->getResult();

            return $rows;
        }

        return $this->findExact($qb, $references, max(1, $criteria->limit), max(0, $criteria->offset));
    }

    /** Number of rows matching the criteria (limit / offset ignored). */
    public function countByCriteria(ActionLogCriteria $criteria): int
    {
        if ($criteria->matchesNothing()) {
            return 0;
        }

        $references = $this->exactFilter($criteria);
        if ($references === false) {
            return 0;
        }

        if ($references === null) {
            return (int) $this->createCriteriaQueryBuilder($criteria)
                ->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $this->countExact($this->createCriteriaQueryBuilder($criteria), $references);
    }

    /**
     * Newest rows across the whole log.
     *
     * @return list<T>
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->findByCriteria(new ActionLogCriteria(limit: $limit));
    }

    /**
     * Rows where the user is the actor or the subject, newest first.
     *
     * @param list<BackedEnum|string>|null $actions allow-list (`null` = any action, `[]` = none)
     *
     * @return list<T>
     */
    public function findInvolving(
        object|int|string $user,
        ?array $actions = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100,
    ): array {
        return $this->findByCriteria(new ActionLogCriteria(involving: $user, actions: $actions, from: $from, to: $to, limit: $limit));
    }

    /**
     * Since 2.1: one newest-first page of {@see ActionLogView} snapshots after `$after` (see
     * {@see ActionLogReaderInterface::pageByCriteria()}). Asks one row more than the page size to know whether a next
     * page exists. With a scope filter, or in id mode with an actor / subject / involving filter, it reads keyset
     * batches of SQL candidates and keeps only the rows that hold the filter scope / ids byte for byte, so look-alike
     * rows never take a slot.
     *
     * Id-mode rows are read with array hydration: nothing enters the entity manager's identity map, so a long-running
     * worker does not grow it. Relation-mode rows are hydrated as entities (their user ids come from the related
     * users) and stay managed, as with {@see findByCriteria()}.
     */
    public function pageByCriteria(ActionLogCriteria $criteria, ?ActionLogCursor $after = null): ActionLogPage
    {
        if ($criteria->matchesNothing()) {
            return new ActionLogPage([]);
        }

        $references = $this->exactFilter($criteria);
        if ($references === false) {
            return new ActionLogPage([]);
        }

        $limit         = min(max(1, $criteria->limit), ActionLogReaderInterface::MAX_LIMIT);
        $byAssociation = $this->referencesByAssociation();
        $qb            = $this->createCriteriaQueryBuilder($criteria);
        if ($byAssociation) {
            $qb->leftJoin('a.actor', 'actor')->addSelect('actor')
                ->leftJoin('a.subject', 'subject')->addSelect('subject');
        }
        $qb->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        // Without an exact filter (ids, scopes) SQL is final: one query of limit + 1 rows. With one, read on in batches.
        $batch = $references === null ? $limit + 1 : min(max($limit + 1, self::EXACT_BATCH_SIZE), self::DEFAULT_BATCH_SIZE);
        $qb->setMaxResults($batch);

        $views = [];
        $last  = $after;
        do {
            $page = clone $qb;
            if ($last instanceof ActionLogCursor) {
                $page->andWhere('a.createdAt < :pageAt OR (a.createdAt = :pageAt AND a.id < :pageId)')
                    ->setParameter('pageAt', $last->createdAt, Types::DATETIME_IMMUTABLE)
                    ->setParameter('pageId', $last->id);
            }

            /** @var list<ActionLogEntryInterface>|list<array{id: int|string, action: string, actorId: string|null, subjectId: string|null, context: array<string, mixed>|null, scope: string|null, ipAddress: string|null, createdAt: DateTimeImmutable, correlationId?: string|null, causedBy?: string|null, changes?: array<string, mixed>|null}> $chunk */
            $chunk = $byAssociation ? $page->getQuery()->getResult() : $page->getQuery()->getArrayResult();
            foreach ($chunk as $row) {
                $view = $row instanceof ActionLogEntryInterface ? ActionLogView::fromEntry($row) : $this->viewFromArray($row);
                $last = $view->cursor();
                if ($references !== null && !$this->holdsExactly($view->actorId, $view->subjectId, $view->scope, $view->correlationId, $references)) {
                    continue;
                }

                $views[] = $view;
                if (count($views) > $limit) {
                    break 2;
                }
            }
        } while ($references !== null && count($chunk) === $batch);

        if (count($views) <= $limit) {
            return new ActionLogPage($views);
        }

        $views = array_slice($views, 0, $limit);

        return new ActionLogPage($views, $views[$limit - 1]->cursor());
    }

    /**
     * Since 2.1: the newest rows across the whole log, as snapshots.
     *
     * @return list<ActionLogView>
     */
    public function latestEntries(int $limit = 50): array
    {
        return $this->pageByCriteria(new ActionLogCriteria(limit: $limit))->items;
    }

    /**
     * Since 2.1: rows where the user (by id) is the actor or the subject, newest first, as a page of snapshots.
     *
     * @param list<BackedEnum|string>|null $actions allow-list (`null` = any action, `[]` = none)
     */
    public function pageInvolving(
        int|string $userId,
        ?array $actions = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100,
        ?ActionLogCursor $after = null,
    ): ActionLogPage {
        return $this->pageByCriteria(new ActionLogCriteria(involving: $userId, actions: $actions, from: $from, to: $to, limit: $limit), $after);
    }

    /**
     * Deletes rows older than `$before` in id batches with {@see KeysetDelete} (keyset over the primary key: each batch
     * starts after the last id seen, so retained rows are scanned once, not once per batch). Runs on DBAL, outside the
     * unit of work.
     *
     * @param array<BackedEnum|string>|null $actions only these actions (`null` = every action, `[]` = nothing)
     *
     * @return int number of deleted rows
     */
    public function purgeBefore(DateTimeImmutable $before, ?array $actions, int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        if ($actions === []) {
            return 0;
        }

        $metadata   = $this->getClassMetadata();
        $connection = $this->getEntityManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $quotes     = $this->getEntityManager()->getConfiguration()->getQuoteStrategy();
        $table      = $quotes->getTableName($metadata, $platform);
        $idColumn   = $quotes->getColumnName($metadata->getSingleIdentifierFieldName(), $metadata, $platform);

        $where  = [$quotes->getColumnName('createdAt', $metadata, $platform) . ' < :before'];
        $params = ['before' => $before];
        $types  = ['before' => Types::DATETIME_IMMUTABLE];
        if ($actions !== null) {
            $where[]           = $quotes->getColumnName('action', $metadata, $platform) . ' IN (:actions)';
            $params['actions'] = array_values(array_map(AbstractActionLog::actionValue(...), $actions));
            $types['actions']  = ArrayParameterType::STRING;
        }

        return KeysetDelete::run($connection, $table, implode(' AND ', $where), $params, $types, $batchSize, $idColumn);
    }

    /** Query builder (alias `a`) with every criteria filter applied, without ordering or pagination. */
    protected function createCriteriaQueryBuilder(ActionLogCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a');

        [$actor, $subject] = $this->referencesByAssociation() ? ['a.actor', 'a.subject'] : ['a.actorId', 'a.subjectId'];

        if ($criteria->actor !== null) {
            $qb->andWhere($actor . ' = :actor')->setParameter('actor', $this->referenceValue($criteria->actor));
        }

        if ($criteria->subject !== null) {
            $qb->andWhere($subject . ' = :subject')->setParameter('subject', $this->referenceValue($criteria->subject));
        }

        if ($criteria->involving !== null) {
            $qb->andWhere($actor . ' = :involving OR ' . $subject . ' = :involving')->setParameter('involving', $this->referenceValue($criteria->involving));
        }

        if ($criteria->scopes !== null) {
            $qb->andWhere('a.scope IN (:scopes)')->setParameter('scopes', $criteria->scopes);
        }

        if ($criteria->actions !== null) {
            $qb->andWhere('a.action IN (:actions)')->setParameter('actions', $criteria->actions);
        }

        if ($criteria->from instanceof DateTimeImmutable) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $criteria->from, Types::DATETIME_IMMUTABLE);
        }

        if ($criteria->to instanceof DateTimeImmutable) {
            $qb->andWhere('a.createdAt <= :to')->setParameter('to', $criteria->to, Types::DATETIME_IMMUTABLE);
        }

        if ($criteria->correlationId !== null && $this->tracesCorrelation()) {
            $qb->andWhere('a.correlationId = :correlationId')->setParameter('correlationId', $criteria->correlationId);
        }

        return $qb;
    }

    /** True for {@see AbstractActionLog} entities (`actor` / `subject` relations), false for id columns. */
    protected function referencesByAssociation(): bool
    {
        return $this->getClassMetadata()->hasAssociation('actor');
    }

    /** Since 2.2: true when the entity maps the `correlationId` field ({@see TraceableActionLogInterface}). */
    protected function tracesCorrelation(): bool
    {
        return $this->getClassMetadata()->hasField('correlationId');
    }

    /**
     * Snapshot of an array-hydrated id-mode row (field names and types as mapped by {@see AbstractIdActionLog}; since 2.2
     * the traceability fields when mapped).
     *
     * @param array{id: int|string, action: string, actorId: string|null, subjectId: string|null, context: array<string, mixed>|null, scope: string|null, ipAddress: string|null, createdAt: DateTimeImmutable, correlationId?: string|null, causedBy?: string|null, changes?: array<string, mixed>|null} $row
     */
    private function viewFromArray(array $row): ActionLogView
    {
        return new ActionLogView(
            (int) $row['id'],
            $row['action'],
            $row['actorId'],
            $row['subjectId'],
            $row['context'] ?? [],
            $row['scope'],
            $row['ipAddress'],
            $row['createdAt'],
            $row['correlationId'] ?? null,
            $row['causedBy'] ?? null,
            $row['changes'] ?? null,
        );
    }

    /** The filter value as given for relations; the stored id string for id columns (`''` matches nothing). */
    private function referenceValue(object|int|string $reference): object|int|string
    {
        return $this->referencesByAssociation() ? $reference : (ReferenceId::of($reference) ?? '');
    }

    /**
     * The values a row must hold byte for byte, or `null` when SQL is final (no scope filter, and relation mode or no
     * actor / subject / involving filter), or `false` when a filter id is refused by {@see ReferenceId} (no row can
     * match).
     *
     * - Since 1.15.1 (REQ-SEC-006): the actor / subject / involving ids of an id-mode row.
     * - Since 2.1 (audit YC1-M1): the scopes, in both modes (the `scope` column keeps the table collation).
     * - Since 2.2: the correlation id (`false` when the entity has no such column or the filter is empty).
     *
     * @return array{actor: string|null, subject: string|null, involving: string|null, scopes: list<string>|null, correlation: string|null}|false|null
     */
    private function exactFilter(ActionLogCriteria $criteria): array|false|null
    {
        $filter = ['actor' => null, 'subject' => null, 'involving' => null, 'scopes' => $criteria->scopes, 'correlation' => $criteria->correlationId];
        if ($criteria->correlationId !== null && ($criteria->correlationId === '' || !$this->tracesCorrelation())) {
            return false;
        }
        if (!$this->referencesByAssociation()) {
            foreach (['actor' => $criteria->actor, 'subject' => $criteria->subject, 'involving' => $criteria->involving] as $role => $reference) {
                if ($reference === null) {
                    continue;
                }
                $id = ReferenceId::of($reference);
                if ($id === null) {
                    return false;
                }
                $filter[$role] = $id;
            }
        }

        if ($filter['scopes'] === null && $filter['correlation'] === null && $filter['actor'] === null && $filter['subject'] === null && $filter['involving'] === null) {
            return null;
        }

        return $filter;
    }

    /**
     * Whether a row holds the filter ids and one of the filter scopes byte for byte (`===`). The SQL filter compares
     * with the column collation, which on MySQL / MariaDB folds case, accents and (PAD SPACE) trailing spaces: `Bob`,
     * `bób` and `bob ` would otherwise match `bob`, and `Tenant-A` or `tenant-a ` the scope `tenant-a`.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null, scopes: list<string>|null, correlation: string|null} $filter
     */
    private function holdsExactly(mixed $actorId, mixed $subjectId, mixed $scope, mixed $correlationId, array $filter): bool
    {
        return ($filter['correlation'] === null || $correlationId === $filter['correlation'])
            && ($filter['actor'] === null || $actorId === $filter['actor'])
            && ($filter['subject'] === null || $subjectId === $filter['subject'])
            && ($filter['involving'] === null || $actorId === $filter['involving'] || $subjectId === $filter['involving'])
            && ($filter['scopes'] === null || in_array($scope, $filter['scopes'], true));
    }

    /**
     * Keyset loop over the SQL candidates (newest first): keeps the exact rows only, skips `$offset` of them and reads
     * on until `$limit` are found, so look-alike rows never take the place of a legitimate one in a page.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null, scopes: list<string>|null, correlation: string|null} $references
     *
     * @return list<T>
     */
    private function findExact(QueryBuilder $qb, array $references, int $limit, int $offset): array
    {
        $batch = min(max($limit + $offset, self::EXACT_BATCH_SIZE), self::DEFAULT_BATCH_SIZE);
        $qb->setMaxResults($batch);
        $rows  = [];
        $after = null;

        do {
            $page = clone $qb;
            if ($after !== null) {
                $page->andWhere('a.createdAt < :exactAt OR (a.createdAt = :exactAt AND a.id < :exactId)')
                    ->setParameter('exactAt', $after[0], Types::DATETIME_IMMUTABLE)
                    ->setParameter('exactId', $after[1]);
            }

            /** @var list<ActionLogEntryInterface&T> $chunk */
            $chunk = $page->getQuery()->getResult();
            foreach ($chunk as $row) {
                $after = [$row->getCreatedAt(), $row->getId()];
                if (!$this->holdsExactly($row->getActorId(), $row->getSubjectId(), $row->getScope(), $row instanceof TraceableActionLogInterface ? $row->getCorrelationId() : null, $references)) {
                    continue;
                }
                if ($offset > 0) {
                    --$offset;

                    continue;
                }

                $rows[] = $row;
                if (count($rows) === $limit) {
                    return $rows;
                }
            }
        } while (count($chunk) === $batch);

        return $rows;
    }

    /**
     * Counts the exact rows among the SQL candidates, reading only their id, user id (id mode) and scope columns in
     * keyset batches over the id.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null, scopes: list<string>|null, correlation: string|null} $references
     */
    private function countExact(QueryBuilder $qb, array $references): int
    {
        $qb->select(($this->referencesByAssociation()
            ? 'a.id AS id, a.scope AS scope'
            : 'a.id AS id, a.actorId AS actorId, a.subjectId AS subjectId, a.scope AS scope')
            . ($references['correlation'] === null ? '' : ', a.correlationId AS correlationId'))
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(self::DEFAULT_BATCH_SIZE);
        $count = 0;
        $after = null;

        do {
            $page = clone $qb;
            if ($after !== null) {
                $page->andWhere('a.id < :exactId')->setParameter('exactId', $after);
            }

            /** @var list<array{id: int|string, actorId?: string|null, subjectId?: string|null, scope: string|null, correlationId?: string|null}> $chunk */
            $chunk = $page->getQuery()->getScalarResult();
            foreach ($chunk as $row) {
                $after = $row['id'];
                if ($this->holdsExactly($row['actorId'] ?? null, $row['subjectId'] ?? null, $row['scope'], $row['correlationId'] ?? null, $references)) {
                    ++$count;
                }
            }
        } while (count($chunk) === self::DEFAULT_BATCH_SIZE);

        return $count;
    }
}
