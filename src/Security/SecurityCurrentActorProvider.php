<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Nowo\AuditKitBundle\Model\ReferenceId;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

use function method_exists;

/**
 * Default {@see CurrentActorProviderInterface} (since 1.15): adapts the Symfony Security user of
 * {@see CurrentUserResolverInterface} to an {@see AuditActor} with exactly the rules of earlier versions.
 *
 * - profile: the profile whose `user_class` the user is an instance of; none matching selects the default profile.
 * - id: the user's `getId()` (see {@see ReferenceId}) when the user is an instance of that profile's `user_class`;
 *   never the user identifier (often an e-mail address).
 *
 * @since 1.15.0
 */
final readonly class SecurityCurrentActorProvider implements CurrentActorProviderInterface
{
    public function __construct(
        private CurrentUserResolverInterface $currentUserResolver,
        private ProfileRegistry $registry,
    ) {
    }

    public function currentActor(): ?AuditActor
    {
        $user = $this->currentUserResolver->resolve();
        if (!$user instanceof UserInterface) {
            return null;
        }

        $profile     = $this->registry->resolveForObject($user);
        $operational = $profile ?? $this->registry->getDefault();

        // Only getId(): a user's __toString() is often its e-mail address.
        $id = $user instanceof $operational->userClass && method_exists($user, 'getId') ? ReferenceId::of($user) : null;

        return new AuditActor($id, $profile?->name);
    }
}
