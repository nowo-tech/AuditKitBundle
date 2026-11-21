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
 * @since 2.1.0
 */
final readonly class ActionLogView
{
    /**
     * @param array<string, mixed> $context
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
        );
    }

    /** Cursor that continues a newest-first page after this row. */
    public function cursor(): ActionLogCursor
    {
        return new ActionLogCursor($this->createdAt, $this->id);
    }
}
