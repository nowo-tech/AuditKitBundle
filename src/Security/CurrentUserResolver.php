<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Resolves the authenticated user from Symfony Security token storage. */
final class CurrentUserResolver
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /** Returns the current user or null when guest / CLI context. */
    public function resolve(): ?UserInterface
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        return $token->getUser();
    }
}
