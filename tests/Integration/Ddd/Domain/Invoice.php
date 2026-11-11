<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain;

use DateTimeImmutable;

/**
 * Plain-PHP domain entity of a strict DDD host: no bundle interface, trait or attribute, no Doctrine attribute
 * (mapped in config/doctrine/Invoice.orm.xml), UUID string id, blame fields are user ids (no user relation).
 */
class Invoice
{
    private ?DateTimeImmutable $createdAt = null;

    private ?DateTimeImmutable $updatedAt = null;

    private ?string $createdBy = null;

    private ?string $updatedBy = null;

    public function __construct(private readonly string $id, private string $title)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?string $updatedBy): void
    {
        $this->updatedBy = $updatedBy;
    }
}
