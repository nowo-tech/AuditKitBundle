<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

/**
 * Since 2.2: a contributor of timeline entries (tag `nowo_audit_kit.timeline_source`, added automatically to services
 * that implement it when autoconfigured). The action log contributes through {@see ActionLogTimelineSource}; another
 * package or the host adds its own (task changes and comments, settings changes, …).
 *
 * A source returns, newest first, at most `$limit` entries about `$subject` that match `$criteria` and come after
 * `$after` (see {@see TimelineCursor::boundFor()} with its own source name); all with the same `source` name. It applies
 * its own visibility rules: the merger shows what the sources return. A subject type it does not know gives `[]`.
 *
 * Sources must be stateless (FrankenPHP worker mode): read on every call, keep nothing between calls.
 *
 * @since 2.2.0
 */
interface TimelineSourceInterface
{
    /**
     * @return list<TimelineEvent>
     */
    public function page(TimelineSubject $subject, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array;
}
