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

use function count;
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
 * @template T of ActionLogEntryInterface
 *
 * @extends ServiceEntityRepository<T>
 */
class ActionLogRepository extends ServiceEntityRepository
{
    public const DEFAULT_BATCH_SIZE = KeysetDelete::DEFAULT_BATCH_SIZE;

    /** Since 1.15.1: smallest batch of the exact-match loop of {@see findByCriteria()} in id mode. */
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

        $references = $this->exactReferences($criteria);
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

        $references = $this->exactReferences($criteria);
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

        return $qb;
    }

    /** True for {@see AbstractActionLog} entities (`actor` / `subject` relations), false for id columns. */
    protected function referencesByAssociation(): bool
    {
        return $this->getClassMetadata()->hasAssociation('actor');
    }

    /** The filter value as given for relations; the stored id string for id columns (`''` matches nothing). */
    private function referenceValue(object|int|string $reference): object|int|string
    {
        return $this->referencesByAssociation() ? $reference : (ReferenceId::of($reference) ?? '');
    }

    /**
     * Since 1.15.1 (REQ-SEC-006): the ids an id-mode row must hold byte for byte, `null` when the criteria filter no
     * id column (relation mode, or no actor / subject / involving filter), `false` when a filter id is refused by
     * {@see ReferenceId} (no row can match).
     *
     * @return array{actor: string|null, subject: string|null, involving: string|null}|false|null
     */
    private function exactReferences(ActionLogCriteria $criteria): array|false|null
    {
        if ($this->referencesByAssociation() || ($criteria->actor === null && $criteria->subject === null && $criteria->involving === null)) {
            return null;
        }

        $references = [];
        foreach (['actor' => $criteria->actor, 'subject' => $criteria->subject, 'involving' => $criteria->involving] as $role => $reference) {
            $id = $reference === null ? null : ReferenceId::of($reference);
            if ($reference !== null && $id === null) {
                return false;
            }
            $references[$role] = $id;
        }

        return $references;
    }

    /**
     * Whether an id-mode row holds the filter ids byte for byte (`===`). The SQL filter compares with the column
     * collation, which on MySQL / MariaDB folds case, accents and (PAD SPACE) trailing spaces: `Bob`, `bób` and `bob `
     * would otherwise match `bob`.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null} $references
     */
    private static function holdsExactly(mixed $actorId, mixed $subjectId, array $references): bool
    {
        return ($references['actor'] === null || $actorId === $references['actor'])
            && ($references['subject'] === null || $subjectId === $references['subject'])
            && ($references['involving'] === null || $actorId === $references['involving'] || $subjectId === $references['involving']);
    }

    /**
     * Keyset loop over the SQL candidates (newest first): keeps the exact rows only, skips `$offset` of them and reads
     * on until `$limit` are found, so look-alike rows never take the place of a legitimate one in a page.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null} $references
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

            /** @var list<AbstractIdActionLog&T> $chunk */
            $chunk = $page->getQuery()->getResult();
            foreach ($chunk as $row) {
                $after = [$row->getCreatedAt(), $row->getId()];
                if (!self::holdsExactly($row->getActorId(), $row->getSubjectId(), $references)) {
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
     * Counts the exact rows among the SQL candidates, reading only their id columns in keyset batches over the id.
     *
     * @param array{actor: string|null, subject: string|null, involving: string|null} $references
     */
    private function countExact(QueryBuilder $qb, array $references): int
    {
        $qb->select('a.id AS id, a.actorId AS actorId, a.subjectId AS subjectId')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(self::DEFAULT_BATCH_SIZE);
        $count = 0;
        $after = null;

        do {
            $page = clone $qb;
            if ($after !== null) {
                $page->andWhere('a.id < :exactId')->setParameter('exactId', $after);
            }

            /** @var list<array{id: int|string, actorId: string|null, subjectId: string|null}> $chunk */
            $chunk = $page->getQuery()->getScalarResult();
            foreach ($chunk as $row) {
                $after = $row['id'];
                if (self::holdsExactly($row['actorId'], $row['subjectId'], $references)) {
                    ++$count;
                }
            }
        } while (count($chunk) === self::DEFAULT_BATCH_SIZE);

        return $count;
    }
}
