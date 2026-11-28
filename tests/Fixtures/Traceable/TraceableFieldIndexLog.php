<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\Traceable;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogInterface;
use Nowo\AuditKitBundle\ActionLog\TraceableActionLogTrait;

/** Declares its correlation index by field name: the listener adds no second one. */
#[ORM\Entity]
#[ORM\Table(name: 'tr_field_index_log')]
#[ORM\Index(name: 'idx_tr_field_corr', fields: ['correlationId'])]
class TraceableFieldIndexLog extends AbstractIdActionLog implements TraceableActionLogInterface
{
    use TraceableActionLogTrait;
}
