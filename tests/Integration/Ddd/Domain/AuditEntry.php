<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain;

use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;

/** Action log row of the DDD host: users by id (no foreign key), mapped in config/doctrine/AuditEntry.orm.xml. */
class AuditEntry extends AbstractIdActionLog
{
}
