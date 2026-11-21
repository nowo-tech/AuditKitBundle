<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;

/** A host entity that the host's action log rows point to (an organisation, a board, a project…). */
#[ORM\Entity]
#[ORM\Table(name: 'hr_tenant')]
class HostTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        public string $name = '',
    ) {
    }
}
