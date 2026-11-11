<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * Provides blame accessors and a default ManyToOne mapping to {@see AuditUserInterface}.
 *
 * The bundle resolves {@see AuditUserInterface} to the default profile `user_class` through
 * `doctrine.orm.resolve_target_entities`; declare your own properties instead of the trait to target another class.
 *
 * XML-mapped hosts (a trait cannot carry its own XML mapping): map the two private properties in the entity's
 * `.orm.xml`:
 *
 *     <many-to-one field="createdBy" target-entity="Nowo\AuditKitBundle\Model\AuditUserInterface">
 *         <join-column name="created_by_id" referenced-column-name="id" on-delete="SET NULL"/>
 *     </many-to-one>
 *     <many-to-one field="updatedBy" target-entity="Nowo\AuditKitBundle\Model\AuditUserInterface">
 *         <join-column name="updated_by_id" referenced-column-name="id" on-delete="SET NULL"/>
 *     </many-to-one>
 *
 * To reference users by id instead (no relation, no foreign key), skip the trait: declare `?string $createdBy` /
 * `?string $updatedBy` id columns and set `nowo_audit_kit.blame_mode: id` (since 1.14).
 */
trait BlameableTrait
{
    #[ORM\ManyToOne(targetEntity: AuditUserInterface::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $createdBy = null;

    #[ORM\ManyToOne(targetEntity: AuditUserInterface::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?object $updatedBy = null;

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?object $createdBy): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->createdBy = $createdBy;
    }

    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?object $updatedBy): void
    {
        // @igor-ignore - Domain model value object; instance-scoped, not a shared service.
        $this->updatedBy = $updatedBy;
    }
}
