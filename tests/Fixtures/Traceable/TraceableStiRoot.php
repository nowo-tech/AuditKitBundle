<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogInterface;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tr_sti_log')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['root' => TraceableStiRoot::class, 'child' => TraceableStiChild::class])]
class TraceableStiRoot extends AbstractIdActionLog implements TraceableActionLogInterface
{
    use TraceableActionLogTrait;
}
