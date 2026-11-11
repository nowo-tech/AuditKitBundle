<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Default Doctrine mappings and accessors for created/updated timestamps.
 *
 * XML-mapped hosts (a trait cannot carry its own XML mapping): map the two private properties in the entity's
 * `.orm.xml` (columns as the default `underscore_number_aware` naming strategy would name them):
 *
 *     <field name="createdAt" type="datetime_immutable" column="created_at" nullable="true"/>
 *     <field name="updatedAt" type="datetime_immutable" column="updated_at" nullable="true"/>
 *
 * Or declare the properties in the entity without the trait and list the class under `auditable_entities`.
 */
trait TimestampableTrait
{
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->createdAt = $createdAt instanceof DateTimeImmutable
            ? $createdAt
            : DateTimeImmutable::createFromInterface($createdAt);
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->updatedAt = $updatedAt instanceof DateTimeImmutable
            ? $updatedAt
            : DateTimeImmutable::createFromInterface($updatedAt);
    }
}
