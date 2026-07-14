<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use DateTimeInterface;

/** Contract for entities with created/updated timestamps. */
interface TimestampableInterface
{
    public function getCreatedAt(): ?DateTimeInterface;

    public function setCreatedAt(DateTimeInterface $createdAt): void;

    public function getUpdatedAt(): ?DateTimeInterface;

    public function setUpdatedAt(DateTimeInterface $updatedAt): void;
}
