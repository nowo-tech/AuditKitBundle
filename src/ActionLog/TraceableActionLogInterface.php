<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use Nowo\AuditKitBundle\Change\ChangeSet;
use Nowo\AuditKitBundle\Doctrine\TraceableActionLogMetadataListener;

/**
 * Since 2.2: an action log entity that stores traceability columns. Implement it on your log entity (subclass of
 * {@see AbstractActionLog} or {@see AbstractIdActionLog}) with {@see TraceableActionLogTrait}:
 *
 * - `correlation_id` (string 64, nullable, indexed): the id of the request, message or command that wrote the row
 *   (nowo-tech/correlation-contract), so one search finds every row of one unit of work;
 * - `caused_by` (string 64, nullable): the id one step back in the chain, when the correlation provider tracks it;
 * - `changes` (json, nullable): what the action changed, as a {@see ChangeSet} (secrets masked, size bounded).
 *
 * Opt-in so that 2.x hosts that do not migrate keep working: entities that do not implement it map no new column.
 * {@see TraceableActionLogMetadataListener} maps the fields (and the `correlation_id`
 * index) for entities that do not map them themselves, with attributes or XML alike.
 *
 * Reserved for a later release (hash chain): a `redacted_at` column on this entity and separate seal / checkpoint
 * tables keyed by the row id. Nothing here has to change for them.
 *
 * @since 2.2.0
 */
interface TraceableActionLogInterface
{
    /** Length of the `correlation_id` / `caused_by` columns (nowo-tech/correlation-contract `CorrelationId::MAX_LENGTH`). */
    public const CORRELATION_LENGTH = 64;

    public function getCorrelationId(): ?string;

    public function setCorrelationId(?string $correlationId): static;

    public function getCausedBy(): ?string;

    public function setCausedBy(?string $causedBy): static;

    /**
     * @return array<string, mixed>|null
     */
    public function getChanges(): ?array;

    /**
     * @param array<string, mixed>|ChangeSet|null $changes an empty set is stored as null; an array is stored raw (no
     *                                                     masking, no cap)
     */
    public function setChanges(ChangeSet|array|null $changes): static;
}
