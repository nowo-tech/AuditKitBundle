<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only, non-null `createdAt` (`datetime_immutable`), set once by {@see initializeCreatedAt()}
 * (typically from the constructor).
 *
 * Alternative to {@see TimestampableTrait} (nullable `createdAt` / `updatedAt` written by the audit listener) for
 * rows that are never updated (logs, events, history). Do not combine both traits on the same entity.
 */
trait CreatedAtImmutableTrait
{
    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    protected function initializeCreatedAt(): void
    {
        // @igor-ignore - Doctrine entity trait; instance-scoped, not a shared service.
        $this->createdAt = new DateTimeImmutable();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
