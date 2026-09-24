<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_object;
use function method_exists;

/** Resolves the authenticated user from Symfony Security token storage. */
final class CurrentUserResolver
{
    /**
     * @param object|null $firewallMap SecurityBundle's `security.firewall.map` (optional, the bundle does not require SecurityBundle)
     */
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?object $firewallMap = null,
    ) {
    }

    /** Returns the current user or null when guest / CLI context. */
    public function resolve(): ?UserInterface
    {
        if (!$this->isTokenTrustedForCurrentRequest()) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        return $token->getUser();
    }

    /**
     * Only a firewall with security enabled sets (or clears) the token on each HTTP request. Elsewhere,
     * when nothing resets services between requests (FrankenPHP worker mode), the token storage may still
     * hold the previous request's user. Without a main request (CLI, Messenger) the caller owns the token.
     */
    private function isTokenTrustedForCurrentRequest(): bool
    {
        if (!$this->requestStack instanceof RequestStack || $this->firewallMap === null) {
            return true;
        }

        $request = $this->requestStack->getMainRequest();
        if (!$request instanceof Request) {
            return true;
        }

        if (!$request->attributes->has('_firewall_context')) {
            return false;
        }

        if (!method_exists($this->firewallMap, 'getFirewallConfig')) {
            return true;
        }

        $config = $this->firewallMap->getFirewallConfig($request);

        return is_object($config) && method_exists($config, 'isSecurityEnabled') && $config->isSecurityEnabled() === true;
    }
}
