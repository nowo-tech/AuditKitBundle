<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\EntityReferenceBlameValueResolver;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

/** Sets timestamp and blame fields on Doctrine persist and update events. */
final readonly class AuditableEntityListener
{
    private BlameValueResolverInterface $blameValueResolver;

    /**
     * @param BlameValueResolverInterface|null $blameValueResolver null: {@see EntityReferenceBlameValueResolver} (the behaviour before 1.12)
     */
    public function __construct(
        private ProfileRegistry $registry,
        private AuditablePropertyResolver $propertyResolver,
        private CurrentUserResolverInterface $currentUserResolver,
        EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        LoggerInterface $logger = new NullLogger(),
        ?BlameValueResolverInterface $blameValueResolver = null,
    ) {
        $this->blameValueResolver = $blameValueResolver ?? new EntityReferenceBlameValueResolver($currentUserResolver, $entityManager, $logger);
    }

    public function prePersist(object $entity, PrePersistEventArgs $event): void
    {
        $profile = $this->resolveOperationalProfile();
        if (!$profile->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        $now = $this->createTimestamp($profile);

        if ($profile->timestampable && $this->propertyResolver->hasTimestampFields($entity, $profile->fields)) {
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setTimestamp($entity, 'created_at', $now, $profile->fields);
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $now, $profile->fields);
        }

        if ($profile->blameable && $this->propertyResolver->hasBlameFields($entity, $profile->fields)) {
            $user = $this->blameValueResolver->resolve($profile, $event->getObjectManager());
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setBlame($entity, 'created_by', $user, $profile->fields);
            // @igor-ignore - Not shared worker service state.
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
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $this->createTimestamp($profile), $profile->fields);
        }

        if ($profile->blameable && $this->propertyResolver->hasBlameFields($entity, $profile->fields)) {
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setBlame($entity, 'updated_by', $this->blameValueResolver->resolve($profile, $event->getObjectManager()), $profile->fields);
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
        $now = $this->clock->now();

        return $profile->timestampType === 'datetime'
            ? DateTime::createFromImmutable($now)
            : $now;
    }
}
