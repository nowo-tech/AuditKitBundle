<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

/**
 * Since 2.2: what a timeline is about: a `type` the sources agree on (`user`, `scope`, `correlation`, or a type of the
 * host or of another package, such as `task`) and the id of that thing. A source returns nothing for a type it does not
 * know.
 *
 * @since 2.2.0
 */
final readonly class TimelineSubject
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
    }
}
