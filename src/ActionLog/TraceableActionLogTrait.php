<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use Nowo\AuditKitBundle\Change\ChangeSet;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;

/**
 * Since 2.2: properties and accessors of {@see TraceableActionLogInterface}. No mapping here: the bundle maps the
 * fields on entities that do not map them themselves (columns `correlation_id`, `caused_by`, `changes`), so the trait
 * works with attribute and XML mappings alike. Map a field yourself to rename its column.
 *
 * @since 2.2.0
 */
trait TraceableActionLogTrait
{
    protected ?string $correlationId = null;

    protected ?string $causedBy = null;

    /** @var array<string, mixed>|null */
    protected ?array $changes = null;

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?string $correlationId): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->correlationId = $correlationId === '' ? null : $correlationId;

        return $this;
    }

    public function getCausedBy(): ?string
    {
        return $this->causedBy;
    }

    public function setCausedBy(?string $causedBy): static
    {
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->causedBy = $causedBy === '' ? null : $causedBy;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    /**
     * A {@see ChangeSet} built with {@see ChangeSetBuilder} is masked and bounded; an array is
     * stored raw (no masking, no cap), for hosts that build the value themselves.
     *
     * @param array<string, mixed>|ChangeSet|null $changes
     */
    public function setChanges(ChangeSet|array|null $changes): static
    {
        $changes = $changes instanceof ChangeSet ? $changes->toArray() : $changes;
        // @igor-ignore - Doctrine entity; instance-scoped, not a shared service.
        $this->changes = $changes === [] ? null : $changes;

        return $this;
    }
}
