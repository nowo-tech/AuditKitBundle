<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/**
 * A host's action log whose to-one associations cascade persist to host entities, as the ORM's "new entity found
 * through the relationship" hint suggests. Refused in isolated mode: persist() would insert the host's entity again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hr_cascade_action_log')]
class HostCascadeActionLog extends AbstractIdActionLog implements TenantStampedLog
{
    #[ORM\ManyToOne(targetEntity: HostTenant::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'tenant_id', onDelete: 'CASCADE')]
    public ?HostTenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: HostKeyedTenant::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'keyed_tenant_id', onDelete: 'CASCADE')]
    public ?HostKeyedTenant $keyed = null;

    public function stamp(TenantStampingListener $from): void
    {
        $this->tenant = $from->current;
        $this->keyed  = $from->keyed;
    }
}
