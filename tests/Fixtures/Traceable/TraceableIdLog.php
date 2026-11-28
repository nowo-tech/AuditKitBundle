<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogInterface;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogTrait;

/** Id-mode log that opts in to the traceability columns (mapped by the bundle's listener). */
#[ORM\Entity]
#[ORM\Table(name: 'tr_id_log')]
class TraceableIdLog extends AbstractIdActionLog implements TraceableActionLogInterface
{
    use TraceableActionLogTrait;
}
