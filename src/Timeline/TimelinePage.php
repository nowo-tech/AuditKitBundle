<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

/**
 * Since 2.2: one page of a merged timeline and the cursor of the next page (null on the last page).
 *
 * @since 2.2.0
 */
final readonly class TimelinePage
{
    /**
     * @param list<TimelineEvent> $items
     */
    public function __construct(
        public array $items,
        public ?TimelineCursor $next = null,
    ) {
    }
}
