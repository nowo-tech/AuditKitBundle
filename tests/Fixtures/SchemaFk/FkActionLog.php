<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\Owner\FkOwner;

/**
 * Audit XI-6: a host's id-mode action log that adds its own associations. 1.15.1–1.18.0 lost the foreign keys of the
 * to-one ones (and their indexes) from the generated schema on MySQL / MariaDB with DBAL 4.5+ and ORM 3.7–3.8.
 */
#[ORM\Entity]
#[ORM\Table(name: 'xi_fk_action_log')]
class FkActionLog extends AbstractIdActionLog
{
    #[ORM\ManyToOne(targetEntity: FkOwner::class)]
    #[ORM\JoinColumn(name: 'owner_id', onDelete: 'CASCADE')]
    public ?FkOwner $owner = null;

    #[ORM\OneToOne(targetEntity: FkOwner::class)]
    #[ORM\JoinColumn(name: 'reviewer_id', onDelete: 'SET NULL')]
    public ?FkOwner $reviewer = null;

    /** @var Collection<int, FkOwner> */
    #[ORM\ManyToMany(targetEntity: FkOwner::class)]
    #[ORM\JoinTable(name: 'xi_fk_action_log_watcher')]
    public Collection $watchers;

    public function __construct()
    {
        parent::__construct();
        $this->watchers = new ArrayCollection();
    }
}
