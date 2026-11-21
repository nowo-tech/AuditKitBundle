<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Event\PrePersistEventArgs;

/**
 * A host Doctrine listener on the shared event manager: it stamps every new action log row with the current tenant,
 * an entity loaded and held by the host's entity manager (as a request-scoped "current organisation" would be).
 */
final class TenantStampingListener
{
    public ?HostTenant $current = null;

    public ?HostTenant $reviewer = null;

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TenantStampedLog && $this->current instanceof HostTenant) {
            $entity->stamp($this->current, $this->reviewer);
        }
    }
}
