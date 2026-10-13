<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Beacon\Adopted;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\Beacon\LegacyActionType;

/**
 * Beacon `UserAction` after adoption: same table, columns, FKs and indexes (documented in docs/USAGE.md).
 *
 * `scope` keeps the `project_uuid` column (36); `subject` keeps `subject_user_id`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_action')]
#[ORM\Index(name: 'idx_user_action_subject_created', columns: ['subject_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_actor_created', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_user_action_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_user_action_project_created', columns: ['project_uuid', 'created_at'])]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'scope', column: new ORM\Column(name: 'project_uuid', length: 36, nullable: true)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(name: 'subject', joinColumns: [
        new ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL'),
    ]),
])]
class UserAction extends AbstractActionLog
{
    /** Host-typed accessor: the kit stores the enum backing value. */
    public function getActionType(): LegacyActionType
    {
        return LegacyActionType::from($this->getAction());
    }
}
