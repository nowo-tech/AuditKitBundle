<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;
use Nowo\AuditKitBundle\Model\ReferenceId;
use Symfony\Component\Uid\AbstractUid;

/**
 * Port for recording action log rows by user **id** (since 1.14), for hosts that hold ids, not user entities.
 *
 * Works with both superclasses: with {@see AbstractIdActionLog} the ids are stored as they are (no foreign key);
 * with {@see AbstractActionLog} they become Doctrine references to the actor / subject target entity. Ids are
 * normalised by {@see ReferenceId} (`null`, `''`, `'0'`, zero and negative ints mean
 * "no user"). The default implementation is {@see ActionLogRecorder}.
 *
 * @since 1.14.0
 */
interface ActionLogIdRecorderInterface
{
    /**
     * Creates and persists a row (not flushed).
     *
     * @param AbstractUid|int|object|string|null $actorId id of the user who acted (null: system)
     * @param AbstractUid|int|object|string|null $subjectId id of the user the action is about
     * @param array<string, mixed> $context UI-facing metadata (names, roles, UUIDs, …)
     */
    public function recordIds(
        string|BackedEnum $action,
        mixed $actorId = null,
        mixed $subjectId = null,
        array $context = [],
        ?string $scope = null,
    ): ActionLogEntryInterface;

    /**
     * Creates, persists and flushes a row (only the entity manager that maps the action log class).
     *
     * @param AbstractUid|int|object|string|null $actorId
     * @param AbstractUid|int|object|string|null $subjectId
     * @param array<string, mixed> $context
     */
    public function recordIdsAndFlush(
        string|BackedEnum $action,
        mixed $actorId = null,
        mixed $subjectId = null,
        array $context = [],
        ?string $scope = null,
    ): ActionLogEntryInterface;
}
