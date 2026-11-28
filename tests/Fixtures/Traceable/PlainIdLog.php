<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/** A 2.1-style log that does not opt in: no traceability column. */
#[ORM\Entity]
#[ORM\Table(name: 'tr_plain_log')]
class PlainIdLog extends AbstractIdActionLog
{
}
