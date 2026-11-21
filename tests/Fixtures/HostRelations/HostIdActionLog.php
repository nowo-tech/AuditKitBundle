<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/** A host's id-mode action log with its own to-one associations to host entities (and a to-many one, left alone). */
#[ORM\Entity]
#[ORM\Table(name: 'hr_id_action_log')]
class HostIdActionLog extends AbstractIdActionLog implements TenantStampedLog
{
    #[ORM\ManyToOne(targetEntity: HostTenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', onDelete: 'CASCADE')]
    public ?HostTenant $tenant = null;

    #[ORM\OneToOne(targetEntity: HostTenant::class)]
    #[ORM\JoinColumn(name: 'reviewer_tenant_id', onDelete: 'SET NULL')]
    public ?HostTenant $reviewer = null;

    #[ORM\ManyToOne(targetEntity: HostTenantProfile::class)]
    #[ORM\JoinColumn(name: 'profile_tenant_id', referencedColumnName: 'tenant_id', onDelete: 'SET NULL')]
    public ?HostTenantProfile $profile = null;

    #[ORM\ManyToOne(targetEntity: HostParty::class)]
    #[ORM\JoinColumn(name: 'party_id', onDelete: 'SET NULL')]
    public ?HostParty $party = null;

    /** @var Collection<int, HostTenant> */
    #[ORM\ManyToMany(targetEntity: HostTenant::class)]
    #[ORM\JoinTable(name: 'hr_id_action_log_watcher')]
    public Collection $watchers;

    public function __construct()
    {
        parent::__construct();
        $this->watchers = new ArrayCollection();
    }

    public function stamp(TenantStampingListener $from): void
    {
        $this->tenant   = $from->current;
        $this->reviewer = $from->reviewer;
        $this->profile  = $from->profile;
        $this->party    = $from->party;
    }
}
