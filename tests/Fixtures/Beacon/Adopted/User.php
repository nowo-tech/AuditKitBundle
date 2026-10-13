<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Beacon\Adopted;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
