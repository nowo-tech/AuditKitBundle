<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

/** Action log rows the host's {@see TenantStampingListener} stamps with the host entities it holds. */
interface TenantStampedLog
{
    public function stamp(TenantStampingListener $from): void;
}
