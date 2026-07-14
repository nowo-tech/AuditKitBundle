<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CurrentUserResolver
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function resolve(): ?UserInterface
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
            return null;
        }

        return $token->getUser();
    }
}
