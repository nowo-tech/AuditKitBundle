<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Default {@see ActionLogReaderInterface} (since 2.1): forwards to the repository of the configured action log entity
 * when it extends {@see ActionLogRepository} (a host subclass keeps its own query tweaks), else to a plain
 * {@see ActionLogRepository} built for the entity class.
 *
 * Stateless: the repository is resolved on every call through the registry, so a long-running worker never holds an
 * entity manager the host has reset or closed between requests (FrankenPHP worker mode, kernel reset off).
 *
 * @since 2.1.0
 */
final readonly class ActionLogReader implements ActionLogReaderInterface
{
    /**
     * @param class-string<ActionLogEntryInterface> $entityClass
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityClass,
    ) {
    }

    public function pageByCriteria(ActionLogCriteria $criteria, ?ActionLogCursor $after = null): ActionLogPage
    {
        return $this->repository()->pageByCriteria($criteria, $after);
    }

    public function countByCriteria(ActionLogCriteria $criteria): int
    {
        return $this->repository()->countByCriteria($criteria);
    }

    public function latestEntries(int $limit = 50): array
    {
        return $this->repository()->latestEntries($limit);
    }

    public function pageInvolving(
        int|string $userId,
        ?array $actions = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        int $limit = 100,
        ?ActionLogCursor $after = null,
    ): ActionLogPage {
        return $this->repository()->pageInvolving($userId, $actions, $from, $to, $limit, $after);
    }

    /**
     * @return ActionLogRepository<ActionLogEntryInterface>
     */
    private function repository(): ActionLogRepository
    {
        $repository = $this->registry->getRepository($this->entityClass);

        return $repository instanceof ActionLogRepository ? $repository : new ActionLogRepository($this->registry, $this->entityClass);
    }
}
