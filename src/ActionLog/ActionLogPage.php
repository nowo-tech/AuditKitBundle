<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

/**
 * One newest-first page of {@see ActionLogView} rows (since 2.1). `next` is the cursor of the following page, `null`
 * on the last one.
 *
 * @since 2.1.0
 */
final readonly class ActionLogPage
{
    /**
     * @param list<ActionLogView> $items
     */
    public function __construct(
        public array $items,
        public ?ActionLogCursor $next = null,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->next instanceof ActionLogCursor;
    }
}
