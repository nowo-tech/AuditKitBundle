<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

/**
 * Port: who is acting right now, by id and profile name (since 1.15). Framework-free twin of
 * {@see CurrentUserResolverInterface}.
 *
 * The audit listener reads it to pick the profile of the current actor and, with `blame_mode: id`, the blame value.
 * The default {@see SecurityCurrentActorProvider} adapts {@see CurrentUserResolverInterface} (Symfony Security token)
 * with the behaviour of earlier versions. Alias this interface to your own service to supply the actor from your
 * domain (a DDD host without Symfony Security, a Messenger worker replaying the command's initiator, a CLI user).
 *
 * @since 1.15.0
 */
interface CurrentActorProviderInterface
{
    /** The current actor, or null for a guest / CLI context (default profile, no blame). */
    public function currentActor(): ?AuditActor;
}
