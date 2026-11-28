<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TraceableStiChild extends TraceableStiRoot
{
}
