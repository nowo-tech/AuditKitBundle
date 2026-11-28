<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogInterface;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogTrait;

/** Maps correlationId itself (renamed column, own composite index): the listener leaves both alone. */
#[ORM\Entity]
#[ORM\Table(name: 'tr_renamed_log')]
#[ORM\Index(name: 'idx_tr_renamed_corr_created', columns: ['request_correlation', 'created_at'])]
class TraceableRenamedLog extends AbstractIdActionLog implements TraceableActionLogInterface
{
    use TraceableActionLogTrait;

    #[ORM\Column(name: 'request_correlation', length: 64, nullable: true)]
    protected ?string $correlationId = null;
}
