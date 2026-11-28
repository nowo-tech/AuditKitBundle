<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Timeline;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Change\ChangeSet;

/**
 * Since 2.2: one entry of a merged timeline, contributed by a {@see TimelineSourceInterface}. Ids and scalars only
 * (never an entity), so it can be kept past the request.
 *
 * - `source`: the name of the contributing source (ties at the same instant are ordered by it);
 * - `id`: the id of the entry within its source (the source's own order breaks ties within it);
 * - `kind`: what happened (an action key, `comment.added`, …);
 * - `actorId`, `channel` (`web`, `api`, `cli`, …), `tokenLabel` (the label of the API token used, never the token),
 *   `correlationId`: optional provenance;
 * - `changes`: a {@see ChangeSet} array, when the entry changed something;
 * - `payload`: anything else the source wants to render. Treat `changes` and `payload` as personal data.
 *
 * @since 2.2.0
 */
final readonly class TimelineEvent
{
    /**
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $source,
        public string $id,
        public DateTimeImmutable $occurredAt,
        public string $kind,
        public ?string $actorId = null,
        public ?string $channel = null,
        public ?string $tokenLabel = null,
        public ?string $correlationId = null,
        public ?array $changes = null,
        public array $payload = [],
    ) {
    }

    /** Cursor that continues a timeline after this entry. */
    public function cursor(): TimelineCursor
    {
        return new TimelineCursor($this->occurredAt, $this->source, $this->id);
    }
}
