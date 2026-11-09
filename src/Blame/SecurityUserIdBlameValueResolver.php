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
use function is_object;
use function is_string;
use function method_exists;

/**
 * Opt-in {@see BlameValueResolverInterface} for applications whose firewall user is not the Doctrine `user_class`
 * entity (a security-user wrapper or DTO): the user's `getId()` becomes `getReference($profile->userClass, $id)`,
 * so only the id is required of the security user. A security user that is the entity is handled exactly like
 * {@see EntityReferenceBlameValueResolver}. A user without a usable `getId()` (null, '', '0', zero or a negative int),
 * or whose reference cannot be built, is not blamed. Id objects (Uuid, Ulid, value objects) are passed as they are.
 *
 * The id is trusted as the `user_class` id of the same person: list the security-user classes that carry such ids in
 * `$acceptedUserClasses`, so a user from another provider or another profile (whose id may collide) is not blamed as
 * `user_class`#id. With an empty list every security user is accepted.
 *
 * Enable it with an alias:
 *
 *     Nowo\AuditKitBundle\Blame\BlameValueResolverInterface: '@Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver'
 *     Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver:
 *         arguments:
 *             $acceptedUserClasses: ['App\Infrastructure\Security\SecurityUser']
 *
 * @since 1.12.0
 */
final readonly class SecurityUserIdBlameValueResolver implements BlameValueResolverInterface
{
    /**
     * @param list<class-string> $acceptedUserClasses security-user classes whose `getId()` is the `user_class` id (empty: any)
     */
    public function __construct(
        private CurrentUserResolverInterface $currentUserResolver,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger = new NullLogger(),
        private array $acceptedUserClasses = [],
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

        if (!$this->isAccepted($user)) {
            return null;
        }

        $id = method_exists($user, 'getId') ? $user->getId() : null;
        if (!$this->isUsableId($id)) {
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

    private function isAccepted(UserInterface $user): bool
    {
        if ($this->acceptedUserClasses === []) {
            return true;
        }

        foreach ($this->acceptedUserClasses as $class) {
            if ($user instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** A positive int, a string other than '' and '0', or an id object (Uuid, Ulid, value object). */
    private function isUsableId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }
        if (is_string($id)) {
            return $id !== '' && $id !== '0';
        }

        return is_object($id);
    }
}
