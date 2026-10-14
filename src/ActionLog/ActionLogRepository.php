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

/**
 * Queries and keyset purge for an {@see AbstractActionLog} entity.
 *
 * Extend it in the host (`class UserActionRepository extends ActionLogRepository`, constructor calling
 * `parent::__construct($registry, UserAction::class)`) and point the entity `repositoryClass` to the subclass.
 * Every query goes through the entity metadata, so renamed columns (attribute / association overrides) work.
 *
 * @template T of AbstractActionLog
 *
 * @extends ServiceEntityRepository<T>
 */
class ActionLogRepository extends ServiceEntityRepository
{
    public const DEFAULT_BATCH_SIZE = KeysetDelete::DEFAULT_BATCH_SIZE;

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

        $qb = $this->createCriteriaQueryBuilder($criteria)
            ->leftJoin('a.actor', 'actor')->addSelect('actor')
            ->leftJoin('a.subject', 'subject')->addSelect('subject')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $criteria->limit))
            ->setFirstResult(max(0, $criteria->offset));

        /** @var list<T> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /** Number of rows matching the criteria (limit / offset ignored). */
    public function countByCriteria(ActionLogCriteria $criteria): int
    {
        if ($criteria->matchesNothing()) {
            return 0;
        }

        return (int) $this->createCriteriaQueryBuilder($criteria)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
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

        if ($criteria->actor !== null) {
            $qb->andWhere('a.actor = :actor')->setParameter('actor', $criteria->actor);
        }

        if ($criteria->subject !== null) {
            $qb->andWhere('a.subject = :subject')->setParameter('subject', $criteria->subject);
        }

        if ($criteria->involving !== null) {
            $qb->andWhere('a.actor = :involving OR a.subject = :involving')->setParameter('involving', $criteria->involving);
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
}
