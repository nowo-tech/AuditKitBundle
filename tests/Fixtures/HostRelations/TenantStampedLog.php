<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

/** Action log rows the host's {@see TenantStampingListener} stamps with its current tenant. */
interface TenantStampedLog
{
    public function stamp(HostTenant $tenant, ?HostTenant $reviewer): void;
}
