<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

/** Contract for entities with created/updated user references. */
interface BlameableInterface
{
    public function getCreatedBy(): ?object;

    public function setCreatedBy(?object $createdBy): void;

    public function getUpdatedBy(): ?object;

    public function setUpdatedBy(?object $updatedBy): void;
}
