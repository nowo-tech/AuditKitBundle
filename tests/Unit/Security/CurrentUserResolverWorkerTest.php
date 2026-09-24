<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Security;

use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function is_string;

/**
 * Consecutive requests on the same service instances, with no `kernel.reset` in between.
 */
final class CurrentUserResolverWorkerTest extends TestCase
{
    public function testPreviousRequestUserIsNotReturnedOutsideFirewall(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $tokenStorage = new TokenStorage();
        $requestStack = new RequestStack();
        $resolver     = new CurrentUserResolver($tokenStorage, $requestStack, $this->firewallMap(['main' => true]));

        // Request 1: behind the "main" firewall, Alice is authenticated.
        $requestStack->push($this->request('/admin/articles', 'main'));
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        self::assertSame($alice, $resolver->resolve());
        $requestStack->pop();

        // Request 2: a public endpoint (no firewall); the token storage was not reset.
        $requestStack->push($this->request('/webhook'));
        self::assertNull($resolver->resolve());
    }

    public function testTokenIgnoredOnFirewallWithSecurityDisabled(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        $requestStack = new RequestStack();
        $requestStack->push($this->request('/webhook', 'public'));

        $resolver = new CurrentUserResolver($tokenStorage, $requestStack, $this->firewallMap(['public' => false]));

        self::assertNull($resolver->resolve());
    }

    public function testTokenIgnoredWhenFirewallMapHasNoConfigForRequest(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        $requestStack = new RequestStack();
        $requestStack->push($this->request('/unknown', 'unknown'));

        $resolver = new CurrentUserResolver($tokenStorage, $requestStack, $this->firewallMap([]));

        self::assertNull($resolver->resolve());
    }

    public function testTokenTrustedWithoutMainRequest(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));

        $resolver = new CurrentUserResolver($tokenStorage, new RequestStack(), $this->firewallMap([]));

        self::assertSame($alice, $resolver->resolve());
    }

    public function testFirewallContextSufficesWhenMapCannotDescribeFirewalls(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        $requestStack = new RequestStack();
        $requestStack->push($this->request('/admin', 'main'));

        $resolver = new CurrentUserResolver($tokenStorage, $requestStack, new stdClass());

        self::assertSame($alice, $resolver->resolve());
    }

    public function testPreviousRequestUserIsReplacedByNextAuthenticatedUser(): void
    {
        $alice        = new InMemoryUser('alice', null);
        $bob          = new InMemoryUser('bob', null);
        $tokenStorage = new TokenStorage();
        $requestStack = new RequestStack();
        $resolver     = new CurrentUserResolver($tokenStorage, $requestStack, $this->firewallMap(['main' => true]));

        $requestStack->push($this->request('/admin/a', 'main'));
        $tokenStorage->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        self::assertSame($alice, $resolver->resolve());
        $requestStack->pop();

        // Same worker, no TokenStorage::reset(); firewall replaces the token with Bob.
        $requestStack->push($this->request('/admin/b', 'main'));
        $tokenStorage->setToken(new UsernamePasswordToken($bob, 'main', $bob->getRoles()));
        self::assertSame($bob, $resolver->resolve());
    }

    private function request(string $path, ?string $firewall = null): Request
    {
        $request = Request::create($path);
        if ($firewall !== null) {
            $request->attributes->set('_firewall_context', $firewall);
        }

        return $request;
    }

    /**
     * @param array<string, bool> $securityEnabledByContext
     */
    private function firewallMap(array $securityEnabledByContext): object
    {
        return new class($securityEnabledByContext) {
            /**
             * @param array<string, bool> $securityEnabledByContext
             */
            public function __construct(private readonly array $securityEnabledByContext)
            {
            }

            public function getFirewallConfig(Request $request): ?object
            {
                $context = $request->attributes->get('_firewall_context');
                if (!is_string($context) || !isset($this->securityEnabledByContext[$context])) {
                    return null;
                }

                return new class($this->securityEnabledByContext[$context]) {
                    public function __construct(private readonly bool $securityEnabled)
                    {
                    }

                    public function isSecurityEnabled(): bool
                    {
                        return $this->securityEnabled;
                    }
                };
            }
        };
    }
}
