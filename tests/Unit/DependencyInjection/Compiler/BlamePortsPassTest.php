<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\BlamePortsPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

final class BlamePortsPassTest extends TestCase
{
    public function testReferenceModeIsLeftAlone(): void
    {
        $container = $this->container('reference');
        $container->setAlias(BlameValueResolverInterface::class, SecurityUserIdBlameValueResolver::class);

        $this->process($container);
        $this->assertNull($container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    public function testIdModeWithDefaultsKeepsTheIdPorts(): void
    {
        $container = $this->container('id');

        $this->process($container);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    #[IgnoreDeprecations]
    public function testIdModeKeepsAHostBlameValueResolverOverrideAndDeprecatesIt(): void
    {
        $container = $this->container('id');
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');

        $this->expectUserDeprecationMessage($this->deprecation(BlameValueResolverInterface::class, BlameIdResolverInterface::class));
        $this->process($container);

        $listener = $container->getDefinition(AuditableEntityListener::class);
        $this->assertNull($listener->getArgument('$blameIdResolver'));
        $this->assertEquals(new Reference(BlameValueResolverInterface::class), $listener->getArgument('$blameValueResolver'));
    }

    public function testIdModeWithAHostBlameIdResolverIgnoresTheLegacyOverride(): void
    {
        $container = $this->container('id');
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');
        $container->setAlias(BlameIdResolverInterface::class, 'app.blame_id');

        $this->process($container);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    #[IgnoreDeprecations]
    public function testIdModeDeprecatesAHostCurrentUserResolverOverride(): void
    {
        $container = $this->container('id');
        $container->register(CurrentUserResolverInterface::class);

        $this->expectUserDeprecationMessage($this->deprecation(CurrentUserResolverInterface::class, CurrentActorProviderInterface::class));
        $this->process($container);
    }

    public function testIdModeWithAHostActorProviderDoesNotDeprecateTheLegacyOverride(): void
    {
        $container = $this->container('id');
        $container->register(CurrentUserResolverInterface::class);
        $container->setAlias(CurrentActorProviderInterface::class, 'app.actors');

        $this->process($container);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    public function testSkipsWithoutTheBundleOrTheListener(): void
    {
        $empty = new ContainerBuilder();
        $this->process($empty);
        $this->assertFalse($empty->hasDefinition(AuditableEntityListener::class));

        $container = $this->container('id');
        $container->removeDefinition(AuditableEntityListener::class);
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');
        $this->process($container);
        $this->assertFalse($container->hasDefinition(AuditableEntityListener::class));
    }

    private function container(string $blameMode): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['blame_mode' => $blameMode, 'user_class' => 'App\\Entity\\User']], $container);

        return $container;
    }

    private function process(ContainerBuilder $container): void
    {
        (new BlamePortsPass())->process($container);
    }

    /** @return non-empty-string */
    private function deprecation(string $legacy, string $port): string
    {
        return sprintf('Since nowo-tech/audit-kit-bundle 1.15: With "nowo_audit_kit.blame_mode: id", overriding "%s" is deprecated: alias "%s" to your service instead. 2.0 no longer reads the former in id mode.', $legacy, $port);
    }
}
