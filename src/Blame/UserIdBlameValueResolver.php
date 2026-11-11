<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Blame;

use Doctrine\Persistence\ObjectManager;
use Nowo\AuditKitBundle\Model\ReferenceId;
use Nowo\AuditKitBundle\Profile\ProfileSettings;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function method_exists;

/**
 * {@see BlameValueResolverInterface} for hosts whose blame fields are plain id columns (since 1.14, enabled by
 * `nowo_audit_kit.blame_mode: id`): the current security user's `getId()` as a string, see {@see ReferenceId}.
 * No Doctrine reference, no `user_class` entity and no foreign key are involved; the user identifier (often an
 * e-mail address) is never written. A user that is not an instance of the profile `user_class` (a class or an
 * interface; Symfony `UserInterface` when the profile sets none), or has no usable id, is not blamed.
 *
 * @since 1.14.0
 */
final readonly class UserIdBlameValueResolver implements BlameValueResolverInterface
{
    public function __construct(private CurrentUserResolverInterface $currentUserResolver)
    {
    }

    public function resolve(ProfileSettings $profile, ObjectManager $objectManager): ?string
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user instanceof UserInterface || !$user instanceof $profile->userClass) {
            return null;
        }

        // Only getId(): a user's __toString() is often its e-mail address.
        return method_exists($user, 'getId') ? ReferenceId::of($user->getId()) : null;
    }
}
