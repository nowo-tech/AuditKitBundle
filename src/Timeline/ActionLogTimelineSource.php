<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use DateTimeZone;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;

use function array_filter;
use function array_map;
use function array_values;
use function date_default_timezone_get;
use function min;
use function preg_match;

use const PHP_INT_MAX;

/**
 * Since 2.2: the action log as a timeline source (registered when `action_log.entity_class` is set, name
 * {@see self::NAME}). It reads through {@see ActionLogReaderInterface}, so every filter keeps its byte-for-byte match.
 *
 * Subject types: {@see self::SUBJECT_USER} (rows where the user is the actor or the subject), {@see self::SUBJECT_SCOPE}
 * (rows of a scope) and {@see self::SUBJECT_CORRELATION} (rows of one unit of work, re-checked with `===` on every row);
 * any other type gives no entry. Entries: `id` = the row id, `kind` = the action, `actorId`, `correlationId`,
 * `changes`, and the payload `{subject_id, scope, context}`.
 *
 * The source applies no permission of its own. The subject `correlation` reads every scope that shares the id: keep it
 * for operators. Tenant-facing views use `TimelineSubject('scope', $tenant)` with `TimelineCriteria(correlationId: …)`.
 *
 * Cursors from other sources are compared in the server's timezone at whole seconds (the action log's precision).
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

        $correlationId = $filter['correlationId'] ?? $criteria->correlationId;
        $page          = $this->reader->pageByCriteria(new ActionLogCriteria(
            involving: $filter['involving'] ?? null,
            scope: $filter['scope'] ?? null,
            actions: $criteria->kinds,
            from: $criteria->since,
            to: $criteria->until,
            limit: min($limit, ActionLogReaderInterface::MAX_LIMIT),
            correlationId: $correlationId,
        ), $this->cursor($after));

        // Defence in depth: a reader that ignores the correlation filter (a host override written for 2.1) never widens
        // the page to other units of work.
        $items = $correlationId === null ? $page->items : array_values(array_filter($page->items, static fn (ActionLogView $view): bool => $view->correlationId === $correlationId));

        return array_map($this->event(...), $items);
    }

    /** The action log position after which this source's next entries are (see {@see TimelineCursor::boundFor()}). */
    private function cursor(?TimelineCursor $after): ?ActionLogCursor
    {
        if (!$after instanceof TimelineCursor) {
            return null;
        }

        // The action log stores whole seconds in the server's timezone: compare in that timezone, and treat a cursor with
        // a fraction of a second (another source's) as just after its whole second, whose rows are not shown yet.
        $at       = $after->occurredAt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $second   = $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));
        $fraction = $at->format('u') !== '000000';

        return match ($after->boundFor($this->name)) {
            TimelineCursor::OLDER        => new ActionLogCursor($second, $fraction ? PHP_INT_MAX : 0),
            TimelineCursor::SAME_INSTANT => new ActionLogCursor($second, PHP_INT_MAX),
            default                      => new ActionLogCursor($second, $fraction ? PHP_INT_MAX : (preg_match('/\A[1-9][0-9]{0,17}\z/', $after->id) === 1 ? (int) $after->id : 0)),
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
