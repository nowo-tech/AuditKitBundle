<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Doctrine;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\EntityReferenceBlameValueResolver;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Profile\UnknownProfileException;
use Nowo\AuditKitBundle\Security\AuditActor;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function assert;
use function sprintf;

/** Sets timestamp and blame fields on Doctrine persist and update events. */
final readonly class AuditableEntityListener
{
    private CurrentActorProviderInterface $currentActorProvider;

    private ?BlameValueResolverInterface $blameValueResolver;

    /**
     * Since 1.15 the profile is chosen from {@see CurrentActorProviderInterface} (null: {@see SecurityCurrentActorProvider}
     * over `$currentUserResolver`, the behaviour of earlier versions), and a non-null `$blameIdResolver` (wired with
     * `blame_mode: id`) replaces `$blameValueResolver`. `$currentUserResolver` may then be null.
     *
     * @param BlameValueResolverInterface|null $blameValueResolver null: {@see EntityReferenceBlameValueResolver} (the behaviour before 1.12)
     */
    public function __construct(
        private ProfileRegistry $registry,
        private AuditablePropertyResolver $propertyResolver,
        ?CurrentUserResolverInterface $currentUserResolver,
        EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        LoggerInterface $logger = new NullLogger(),
        ?BlameValueResolverInterface $blameValueResolver = null,
        ?CurrentActorProviderInterface $currentActorProvider = null,
        private ?BlameIdResolverInterface $blameIdResolver = null,
    ) {
        if (!$currentActorProvider instanceof CurrentActorProviderInterface) {
            if (!$currentUserResolver instanceof CurrentUserResolverInterface) {
                throw new InvalidArgumentException(sprintf('%s needs a %s or a %s.', self::class, CurrentActorProviderInterface::class, CurrentUserResolverInterface::class));
            }
            $currentActorProvider = new SecurityCurrentActorProvider($currentUserResolver, $registry);
        }
        $this->currentActorProvider = $currentActorProvider;

        if (!$blameIdResolver instanceof BlameIdResolverInterface && !$blameValueResolver instanceof BlameValueResolverInterface) {
            if (!$currentUserResolver instanceof CurrentUserResolverInterface) {
                throw new InvalidArgumentException(sprintf('%s needs a %s, a %s or a %s.', self::class, BlameIdResolverInterface::class, BlameValueResolverInterface::class, CurrentUserResolverInterface::class));
            }
            $blameValueResolver = new EntityReferenceBlameValueResolver($currentUserResolver, $entityManager, $logger);
        }
        $this->blameValueResolver = $blameValueResolver;
    }

    public function prePersist(object $entity, PrePersistEventArgs $event): void
    {
        $actor   = $this->currentActorProvider->currentActor();
        $profile = $this->resolveOperationalProfile($actor);
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
            $user = $this->resolveBlame($profile, $actor, $event->getObjectManager());
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setBlame($entity, 'created_by', $user, $profile->fields);
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setBlame($entity, 'updated_by', $user, $profile->fields);
        }
    }

    public function preUpdate(object $entity, PreUpdateEventArgs $event): void
    {
        $actor   = $this->currentActorProvider->currentActor();
        $profile = $this->resolveOperationalProfile($actor);
        if (!$profile->enabled || !$this->propertyResolver->isAuditable($entity)) {
            return;
        }

        if ($profile->timestampable && $this->propertyResolver->hasTimestampFields($entity, $profile->fields)) {
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setTimestamp($entity, 'updated_at', $this->createTimestamp($profile), $profile->fields);
        }

        if ($profile->blameable && $this->propertyResolver->hasBlameFields($entity, $profile->fields)) {
            // @igor-ignore - Not shared worker service state.
            $this->propertyResolver->setBlame($entity, 'updated_by', $this->resolveBlame($profile, $actor, $event->getObjectManager()), $profile->fields);
        }
    }

    /** The actor's profile (unknown names throw {@see UnknownProfileException}), else the default profile. */
    private function resolveOperationalProfile(?AuditActor $actor): ProfileSettings
    {
        $name = $actor?->profile;

        return $name !== null ? $this->registry->getByName($name) : $this->registry->getDefault();
    }

    private function resolveBlame(ProfileSettings $profile, ?AuditActor $actor, ObjectManager $objectManager): mixed
    {
        if ($this->blameIdResolver instanceof BlameIdResolverInterface) {
            return $this->blameIdResolver->resolveBlameId($profile, $actor);
        }

        // Non-null here: the constructor guarantees one of the two blame ports.
        assert($this->blameValueResolver instanceof BlameValueResolverInterface);

        return $this->blameValueResolver->resolve($profile, $objectManager);
    }

    private function createTimestamp(ProfileSettings $profile): DateTimeInterface
    {
        $now = $this->clock->now();

        return $profile->timestampType === 'datetime'
            ? DateTime::createFromImmutable($now)
            : $now;
    }
}
