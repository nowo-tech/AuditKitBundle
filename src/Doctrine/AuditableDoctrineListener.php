<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Global Doctrine `prePersist` / `preUpdate` listener: audits every auditable entity without requiring an
 * `#[ORM\EntityListeners]` mapping, delegating to {@see AuditableEntityListener} (which filters with
 * {@see AuditablePropertyResolver::isAuditable()}).
 */
final readonly class AuditableDoctrineListener
{
    public function __construct(
        private AuditableEntityListener $auditor,
    ) {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$this->isMappedAsEntityListener($entity, $event->getObjectManager(), Events::prePersist)) {
            $this->auditor->prePersist($entity, $event);
        }
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$this->isMappedAsEntityListener($entity, $event->getObjectManager(), Events::preUpdate)) {
            $this->auditor->preUpdate($entity, $event);
        }
    }

    /**
     * True when the entity still maps {@see AuditableEntityListener} via `#[ORM\EntityListeners]`: Doctrine then
     * calls it directly, so auditing here as well would run twice.
     */
    private function isMappedAsEntityListener(object $entity, EntityManagerInterface $entityManager, string $eventName): bool
    {
        foreach ($entityManager->getClassMetadata($entity::class)->entityListeners[$eventName] ?? [] as $listener) {
            if ($listener['class'] === AuditableEntityListener::class) {
                return true;
            }
        }

        return false;
    }
}
