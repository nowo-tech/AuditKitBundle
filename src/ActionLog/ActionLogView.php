<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use DateTimeImmutable;

/**
 * Immutable snapshot of one action log row, returned by {@see ActionLogReaderInterface} (since 2.1). It holds ids and
 * scalars only, never the log entity or a user entity, so a host can keep it past the request and the entity
 * manager's lifetime.
 *
 * `actorId` / `subjectId` are the stored ids ({@see AbstractIdActionLog}) or the ids of the related users
 * ({@see AbstractActionLog}, through {@see ActionLogEntryInterface::getActorId()}). Treat `ipAddress` and `context`
 * as personal data.
 *
 * Since 2.2 it also carries the traceability columns of a {@see TraceableActionLogInterface} entity (`correlationId`,
 * `causedBy`, `changes`; null for other entities). Treat `changes` as personal data too.
 *
 * @since 2.1.0
 */
final readonly class ActionLogView
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $changes since 2.2
     */
    public function __construct(
        public int $id,
        public string $action,
        public ?string $actorId,
        public ?string $subjectId,
        public array $context,
        public ?string $scope,
        public ?string $ipAddress,
        public DateTimeImmutable $createdAt,
        public ?string $correlationId = null,
        public ?string $causedBy = null,
        public ?array $changes = null,
    ) {
    }

    /** Snapshot of a persisted row (a row without an id has not been flushed and cannot be paged). */
    public static function fromEntry(ActionLogEntryInterface $entry): self
    {
        return new self(
            (int) $entry->getId(),
            $entry->getAction(),
            $entry->getActorId(),
            $entry->getSubjectId(),
            $entry->getContext(),
            $entry->getScope(),
            $entry->getIpAddress(),
            $entry->getCreatedAt(),
            $entry instanceof TraceableActionLogInterface ? $entry->getCorrelationId() : null,
            $entry instanceof TraceableActionLogInterface ? $entry->getCausedBy() : null,
            $entry instanceof TraceableActionLogInterface ? $entry->getChanges() : null,
        );
    }

    /** Cursor that continues a newest-first page after this row. */
    public function cursor(): ActionLogCursor
    {
        return new ActionLogCursor($this->createdAt, $this->id);
    }
}
