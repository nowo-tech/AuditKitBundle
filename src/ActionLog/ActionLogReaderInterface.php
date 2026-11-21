<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use DateTimeImmutable;

/**
 * Read port over the action log (since 2.1, REQ-ARCH-001 / DDD-host B7): lists, counts and pages rows without the host
 * writing DQL over the log entity or extending {@see ActionLogRepository}. It returns {@see ActionLogView} snapshots
 * (ids and scalars), never entities.
 *
 * Every listing is newest first (`createdAt DESC, id DESC`) with keyset paging through {@see ActionLogCursor}. In id
 * mode ({@see AbstractIdActionLog}) the actor / subject / involving filters match byte for byte (REQ-SEC-006), as
 * {@see ActionLogRepository::findByCriteria()} does. Reads never flush, clear or write to any entity manager.
 *
 * The bundle registers {@see ActionLogReader} under this interface when `action_log.entity_class` is set; it uses the
 * entity's repository when that extends {@see ActionLogRepository}, and a plain {@see ActionLogRepository} otherwise.
 *
 * @since 2.1.0
 */
interface ActionLogReaderInterface
{
    /** Largest page any method returns; a bigger `limit` is lowered to it (the next cursor reaches the rest). */
    public const MAX_LIMIT = 1000;

    /**
     * One page of rows matching the criteria, after `$after` (first page when `null`). `$criteria->limit` is the page
     * size (1 to {@see self::MAX_LIMIT}); `$criteria->offset` is ignored: page with the returned cursor.
     */
    public function pageByCriteria(ActionLogCriteria $criteria, ?ActionLogCursor $after = null): ActionLogPage;

    /** Number of rows matching the criteria (limit / offset ignored). */
    public function countByCriteria(ActionLogCriteria $criteria): int;

    /**
     * Newest rows across the whole log.
     *
     * @return list<ActionLogView>
     */
    public function latestEntries(int $limit = 50): array;

    /**
     * Rows where the user (by id) is the actor or the subject.
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
    ): ActionLogPage;
}
