<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Port: who is acting right now. The default {@see CurrentUserResolver} reads the Symfony Security token storage;
 * alias this interface to your own service to supply the actor elsewhere (a Messenger worker replaying the command's
 * initiator, impersonation, a CLI user).
 *
 * Symfony-typed adapter port. Since 1.15 the framework-free {@see CurrentActorProviderInterface} is the host extension
 * point: its default {@see SecurityCurrentActorProvider} reads this port. With `blame_mode: id`, aliasing this
 * interface to your own service is deprecated (removed in 2.0): alias {@see CurrentActorProviderInterface} instead.
 *
 * @since 1.12.0
 */
interface CurrentUserResolverInterface
{
    /** Returns the current user, or null for a guest / CLI context. */
    public function resolve(): ?UserInterface;
}
