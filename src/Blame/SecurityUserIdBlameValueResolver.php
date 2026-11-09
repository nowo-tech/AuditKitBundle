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

use function is_int;
use function is_string;
use function method_exists;

/**
 * Opt-in {@see BlameValueResolverInterface} for applications whose firewall user is not the Doctrine `user_class`
 * entity (a security-user wrapper or DTO): the user's `getId()` becomes `getReference($profile->userClass, $id)`,
 * so only the id is required of the security user. A security user that is the entity is handled exactly like
 * {@see EntityReferenceBlameValueResolver}. A user without a usable `getId()` (or a reference that cannot be built)
 * is not blamed.
 *
 * Enable it with an alias:
 *
 *     Nowo\AuditKitBundle\Blame\BlameValueResolverInterface: '@Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver'
 *
 * @since 1.12.0
 */
final readonly class SecurityUserIdBlameValueResolver implements BlameValueResolverInterface
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
        if (!$user instanceof UserInterface) {
            return null;
        }

        if ($user instanceof $profile->userClass) {
            return (new EntityReferenceBlameValueResolver($this->currentUserResolver, $this->entityManager, $this->logger))
                ->resolve($profile, $objectManager);
        }

        $id = method_exists($user, 'getId') ? $user->getId() : null;
        if (!is_int($id) && (!is_string($id) || $id === '')) {
            return null;
        }

        $em = $objectManager instanceof EntityManagerInterface ? $objectManager : $this->entityManager;

        try {
            return $em->getReference($profile->userClass, $id);
        } catch (Throwable $e) {
            $this->logger->warning('AuditKit: failed to build a blame reference from the security user id; blame left null.', [
                'bundle'    => 'nowo-tech/audit-kit-bundle',
                'action'    => 'resolve_blame_user_id',
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }

        return null;
    }
}
