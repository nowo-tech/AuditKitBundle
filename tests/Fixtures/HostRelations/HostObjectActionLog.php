<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;

/** A host's relation-mode action log (actor / subject relations) with its own to-one associations to host entities. */
#[ORM\Entity]
#[ORM\Table(name: 'hr_object_action_log')]
class HostObjectActionLog extends AbstractActionLog implements TenantStampedLog
{
    #[ORM\ManyToOne(targetEntity: HostTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', onDelete: 'CASCADE')]
    public ?HostTenant $tenant = null;

    #[ORM\OneToOne(targetEntity: HostTenant::class)]
    #[ORM\JoinColumn(name: 'reviewer_tenant_id', onDelete: 'SET NULL')]
    public ?HostTenant $reviewer = null;

    public function stamp(HostTenant $tenant, ?HostTenant $reviewer): void
    {
        $this->tenant   = $tenant;
        $this->reviewer = $reviewer;
    }
}
