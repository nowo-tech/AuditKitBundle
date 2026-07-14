<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Provides blame accessors and a default ManyToOne mapping.
 *
 * Set targetEntity on your entity class when the user FQCN differs from the default mapping.
 */
trait BlameableTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $updatedBy = null;

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?object $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?object $updatedBy): void
    {
        $this->updatedBy = $updatedBy;
    }
}
