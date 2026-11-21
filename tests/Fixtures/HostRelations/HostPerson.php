<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;

/** A leaf of {@see HostParty}. */
#[ORM\Entity]
class HostPerson extends HostParty
{
}
