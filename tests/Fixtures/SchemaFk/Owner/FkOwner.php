<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\Owner;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\Tests\Fixtures\SchemaFk\FkActionLog;

/** Audit XI-6: the target of the host associations of {@see FkActionLog}. */
#[ORM\Entity]
#[ORM\Table(name: 'xi_fk_owner')]
class FkOwner
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
