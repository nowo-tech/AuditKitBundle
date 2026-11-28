<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use DateTimeImmutable;

/**
 * Since 2.2: filters every source of a timeline applies. `null` means "no filter"; `kinds: []` matches nothing.
 *
 * @since 2.2.0
 */
final readonly class TimelineCriteria
{
    /**
     * @param DateTimeImmutable|null $since inclusive lower bound on `occurredAt`
     * @param DateTimeImmutable|null $until inclusive upper bound on `occurredAt`
     * @param list<string>|null $kinds only these event kinds
     * @param string|null $correlationId only events of this unit of work (sources that cannot tell return none)
     */
    public function __construct(
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
        public ?array $kinds = null,
        public ?string $correlationId = null,
    ) {
    }
}
