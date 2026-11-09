<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Blame;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Default {@see BlameValueResolverInterface}: a Doctrine reference to the current security user when it is an
 * instance of the profile `user_class` (the user itself when no reference can be built), null for any other user.
 *
 * @since 1.12.0
 */
final readonly class EntityReferenceBlameValueResolver implements BlameValueResolverInterface
{
    public function __construct(
        private CurrentUserResolverInterface $currentUserResolver,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): ?object
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user instanceof UserInterface || !$user instanceof $profile->userClass) {
            return null;
        }

        // Prefer the event's ObjectManager (correct EM under multi-manager apps / worker scenario B).
        $em = $objectManager instanceof EntityManagerInterface ? $objectManager : $this->entityManager;

        try {
            $metadata = $em->getClassMetadata($profile->userClass);
            $idField  = $metadata->getSingleIdentifierFieldName();
            $idValues = $metadata->getIdentifierValues($user);
            $idValue  = $idValues[$idField] ?? null;

            if ($idValue !== null) {
                return $em->getReference($profile->userClass, $idValue);
            }
        } catch (Throwable $e) {
            // Fall back to the managed/authenticated user instance.
            $this->logger->warning('AuditKit: failed to resolve blame user Doctrine reference; using token user.', [
                'bundle'    => 'nowo-tech/audit-kit-bundle',
                'action'    => 'resolve_blame_user',
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }

        return $user;
    }
}
