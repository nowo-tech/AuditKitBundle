<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;

use function array_map;
use function min;
use function preg_match;

use const PHP_INT_MAX;

/**
 * Since 2.2: the action log as a timeline source (registered when `action_log.entity_class` is set, name
 * {@see self::NAME}). It reads through {@see ActionLogReaderInterface}, so every filter keeps its byte-for-byte match.
 *
 * Subject types: {@see self::SUBJECT_USER} (rows where the user is the actor or the subject), {@see self::SUBJECT_SCOPE}
 * (rows of a scope) and {@see self::SUBJECT_CORRELATION} (rows of one unit of work); any other type gives no entry.
 * Entries: `id` = the row id, `kind` = the action, `actorId`, `correlationId`, `changes`, and the payload
 * `{subject_id, scope, context}`. The source applies no permission of its own: give the merger only the subjects the
 * reader may see.
 *
 * Stateless.
 *
 * @since 2.2.0
 */
final readonly class ActionLogTimelineSource implements TimelineSourceInterface
{
    public const NAME = 'action_log';

    public const SUBJECT_USER = 'user';

    public const SUBJECT_SCOPE = 'scope';

    public const SUBJECT_CORRELATION = 'correlation';

    public function __construct(
        private ActionLogReaderInterface $reader,
        private string $name = self::NAME,
    ) {
    }

    public function page(TimelineSubject $subject, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array
    {
        $filter = match ($subject->type) {
            self::SUBJECT_USER        => ['involving' => $subject->id],
            self::SUBJECT_SCOPE       => ['scope' => $subject->id],
            self::SUBJECT_CORRELATION => ['correlationId' => $subject->id],
            default                   => null,
        };
        if ($filter === null || ($criteria->correlationId !== null && ($filter['correlationId'] ?? $criteria->correlationId) !== $criteria->correlationId)) {
            return [];
        }

        $page = $this->reader->pageByCriteria(new ActionLogCriteria(
            involving: $filter['involving'] ?? null,
            scope: $filter['scope'] ?? null,
            actions: $criteria->kinds,
            from: $criteria->since,
            to: $criteria->until,
            limit: min($limit, ActionLogReaderInterface::MAX_LIMIT),
            correlationId: $filter['correlationId'] ?? $criteria->correlationId,
        ), $this->cursor($after));

        return array_map($this->event(...), $page->items);
    }

    /** The action log position after which this source's next entries are (see {@see TimelineCursor::boundFor()}). */
    private function cursor(?TimelineCursor $after): ?ActionLogCursor
    {
        if (!$after instanceof TimelineCursor) {
            return null;
        }

        return match ($after->boundFor($this->name)) {
            TimelineCursor::OLDER        => new ActionLogCursor($after->occurredAt, 0),
            TimelineCursor::SAME_INSTANT => new ActionLogCursor($after->occurredAt, PHP_INT_MAX),
            default                      => new ActionLogCursor($after->occurredAt, preg_match('/\A[1-9][0-9]{0,17}\z/', $after->id) === 1 ? (int) $after->id : 0),
        };
    }

    private function event(ActionLogView $view): TimelineEvent
    {
        return new TimelineEvent(
            source: $this->name,
            id: (string) $view->id,
            occurredAt: $view->createdAt,
            kind: $view->action,
            actorId: $view->actorId,
            correlationId: $view->correlationId,
            changes: $view->changes,
            payload: ['subject_id' => $view->subjectId, 'scope' => $view->scope, 'context' => $view->context],
        );
    }
}
