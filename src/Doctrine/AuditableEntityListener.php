<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Throwable;

/** Sets timestamp and blame fields on Doctrine persist and update events. */
final class AuditableEntityListener
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $timestampable,
        private readonly bool $blameable,
        private readonly string $userClass,
        private readonly string $timestampType,
        private readonly AuditablePropertyResolver $propertyResolver,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function prePersist(object $entity, PrePersistEventArgs $event): void
    {
        if (!$this->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        $now = $this->createTimestamp();

        if ($this->timestampable && $this->propertyResolver->hasTimestampFields($entity)) {
            $this->propertyResolver->setTimestamp($entity, 'created_at', $now);
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $now);
        }

        if ($this->blameable && $this->propertyResolver->hasBlameFields($entity)) {
            $user = $this->resolveBlameUser();
            $this->propertyResolver->setBlame($entity, 'created_by', $user);
            $this->propertyResolver->setBlame($entity, 'updated_by', $user);
        }
    }

    public function preUpdate(object $entity, PreUpdateEventArgs $event): void
    {
        if (!$this->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        if ($this->timestampable && $this->propertyResolver->hasTimestampFields($entity)) {
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $this->createTimestamp());
        }

        if ($this->blameable && $this->propertyResolver->hasBlameFields($entity)) {
            $this->propertyResolver->setBlame($entity, 'updated_by', $this->resolveBlameUser());
        }
    }

    private function createTimestamp(): DateTimeInterface
    {
        return $this->timestampType === 'datetime'
            ? new DateTime()
            : new DateTimeImmutable();
    }

    private function resolveBlameUser(): ?object
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return null;
        }

        if (!is_a($user, $this->userClass, true)) {
            return null;
        }

        try {
            $metadata = $this->entityManager->getClassMetadata($this->userClass);
            $idField  = $metadata->getSingleIdentifierFieldName();
            $idValues = $metadata->getIdentifierValues($user);
            $idValue  = $idValues[$idField] ?? null;

            if ($idValue !== null) {
                return $this->entityManager->getReference($this->userClass, $idValue);
            }
        } catch (Throwable) {
            // Fall back to the managed/authenticated user instance.
        }

        return $user;
    }
}
