<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use function array_slice;
use function count;
use function max;
use function min;
use function strcmp;
use function usort;

/**
 * Since 2.2: merges the entries of every {@see TimelineSourceInterface} (tag `nowo_audit_kit.timeline_source`) into one
 * newest-first page. Each source is asked for `limit + 1` entries after the cursor; the merge orders them by
 * `occurredAt` (newest first), then source name, then each source's own order, and keeps `limit`. Page boundaries are
 * stable: an entry is never repeated or skipped from one page to the next, whatever the sources hold at the same
 * instant.
 *
 * Stateless (the sources are injected once and asked on every call).
 *
 * @since 2.2.0
 */
final readonly class TimelineMerger
{
    /** Largest page; a bigger `limit` is lowered to it. */
    public const MAX_LIMIT = 500;

    /**
     * @param iterable<TimelineSourceInterface> $sources
     */
    public function __construct(private iterable $sources = [])
    {
    }

    public function page(TimelineSubject $subject, TimelineCriteria $criteria = new TimelineCriteria(), ?TimelineCursor $after = null, int $limit = 50): TimelinePage
    {
        $limit = min(max(1, $limit), self::MAX_LIMIT);
        if ($criteria->kinds === []) {
            return new TimelinePage([]);
        }

        /** @var list<array{0: TimelineEvent, 1: int}> $entries */
        $entries = [];
        foreach ($this->sources as $source) {
            foreach ($source->page($subject, $criteria, $after, $limit + 1) as $event) {
                $entries[] = [$event, count($entries)];
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$b[0]->occurredAt, strcmp($a[0]->source, $b[0]->source), $a[1]] <=> [$a[0]->occurredAt, 0, $b[1]]);

        $items = [];
        foreach ($entries as [$event]) {
            $items[] = $event;
        }

        if (count($items) <= $limit) {
            return new TimelinePage($items);
        }

        $items = array_slice($items, 0, $limit);

        return new TimelinePage($items, $items[$limit - 1]->cursor());
    }
}
