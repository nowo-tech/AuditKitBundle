<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;

/** A host entity whose identifier is an association (a derived, foreign identifier). */
#[ORM\Entity]
#[ORM\Table(name: 'hr_tenant_profile')]
class HostTenantProfile
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: HostTenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        public HostTenant $tenant,
        #[ORM\Column(length: 64)]
        public string $plan = 'free',
    ) {
    }
}
