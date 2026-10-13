<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\AuditKitBundle\Model\PublicUuidInterface;

/**
 * Global Doctrine `prePersist` listener: ensures every {@see PublicUuidInterface} entity has a UUID before insert.
 * Other entities are left untouched.
 */
final class PublicUuidListener
{
    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if ($entity instanceof PublicUuidInterface) {
            $entity->ensureUuid();
        }
    }
}
