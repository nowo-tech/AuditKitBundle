<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Attribute\Auditable;
use Nowo\AuditKitBundle\Model\AuditableInterface;
use Nowo\AuditKitBundle\Model\TimestampableTrait;

/**
 * Example of per-entity opt-out: audit columns exist but the listener skips them.
 */
#[Auditable(enabled: false)]
#[ORM\Entity]
#[ORM\Table(name: 'legacy_records')]
class LegacyRecord implements AuditableInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?object $createdBy): void
    {
        $this->createdBy = $createdBy instanceof User ? $createdBy : null;
    }

    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?object $updatedBy): void
    {
        $this->updatedBy = $updatedBy instanceof User ? $updatedBy : null;
    }
}
