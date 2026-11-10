<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Since 1.13: a host entity with no AuditKit interface, trait or attribute, audited because it implements a marker
 * interface of the host listed in `nowo_audit_kit.auditable_entities`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kernel_plain_record')]
class KernelPlainRecord implements KernelAuditedRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $title = 'plain';

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
