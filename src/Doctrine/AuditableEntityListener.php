<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/** Sets timestamp and blame fields on Doctrine persist and update events. */
final class AuditableEntityListener
{
    public function __construct(
        private readonly ProfileRegistry $registry,
        private readonly AuditablePropertyResolver $propertyResolver,
        private readonly CurrentUserResolver $currentUserResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function prePersist(object $entity, PrePersistEventArgs $event): void
    {
        $profile = $this->resolveOperationalProfile();
        if (!$profile->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        $now = $this->createTimestamp($profile);

        if ($profile->timestampable && $this->propertyResolver->hasTimestampFields($entity, $profile->fields)) {
            $this->propertyResolver->setTimestamp($entity, 'created_at', $now, $profile->fields);
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $now, $profile->fields);
        }

        if ($profile->blameable && $this->propertyResolver->hasBlameFields($entity, $profile->fields)) {
            $user = $this->resolveBlameUser($profile);
            $this->propertyResolver->setBlame($entity, 'created_by', $user, $profile->fields);
            $this->propertyResolver->setBlame($entity, 'updated_by', $user, $profile->fields);
        }
    }

    public function preUpdate(object $entity, PreUpdateEventArgs $event): void
    {
        $profile = $this->resolveOperationalProfile();
        if (!$profile->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        if ($profile->timestampable && $this->propertyResolver->hasTimestampFields($entity, $profile->fields)) {
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $this->createTimestamp($profile), $profile->fields);
        }

        if ($profile->blameable && $this->propertyResolver->hasBlameFields($entity, $profile->fields)) {
            $this->propertyResolver->setBlame($entity, 'updated_by', $this->resolveBlameUser($profile), $profile->fields);
        }
    }

    private function resolveOperationalProfile(): ProfileSettings
    {
        $user = $this->currentUserResolver->resolve();
        if ($user instanceof UserInterface) {
            $profile = $this->registry->resolveForObject($user);
            if ($profile instanceof ProfileSettings) {
                return $profile;
            }
        }

        return $this->registry->getDefault();
    }

    private function createTimestamp(ProfileSettings $profile): DateTimeInterface
    {
        return $profile->timestampType === 'datetime'
            ? new DateTime()
            : new DateTimeImmutable();
    }

    private function resolveBlameUser(ProfileSettings $profile): ?object
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user instanceof UserInterface) {
            return null;
        }

        if (!$user instanceof $profile->userClass) {
            return null;
        }

        try {
            $metadata = $this->entityManager->getClassMetadata($profile->userClass);
            $idField  = $metadata->getSingleIdentifierFieldName();
            $idValues = $metadata->getIdentifierValues($user);
            $idValue  = $idValues[$idField] ?? null;

            if ($idValue !== null) {
                return $this->entityManager->getReference($profile->userClass, $idValue);
            }
        } catch (Throwable) {
            // Fall back to the managed/authenticated user instance.
        }

        return $user;
    }
}
