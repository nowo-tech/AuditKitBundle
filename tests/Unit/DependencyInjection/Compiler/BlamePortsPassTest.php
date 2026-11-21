<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\SecurityUserIdBlameValueResolver;
use Nowo\AuditKitBundle\DependencyInjection\Compiler\BlamePortsPass;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/** Since 2.0 (Q6): id mode reads only the framework-free ports; an override of a 1.14 port fails the build. */
final class BlamePortsPassTest extends TestCase
{
    public function testReferenceModeIsLeftAlone(): void
    {
        $container = $this->container('reference');
        $container->setAlias(BlameValueResolverInterface::class, SecurityUserIdBlameValueResolver::class);
        $container->register(CurrentUserResolverInterface::class);

        $this->process($container);
        $this->assertNull($container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    public function testIdModeWithDefaultsKeepsTheIdPorts(): void
    {
        $container = $this->container('id');

        $this->process($container);
        $listener = $container->getDefinition(AuditableEntityListener::class);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $listener->getArgument('$blameIdResolver'));
        $this->assertNull($listener->getArgument('$blameValueResolver'));
    }

    public function testIdModeFailsOnAHostBlameValueResolverAlias(): void
    {
        $container = $this->container('id');
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');

        $this->expectBuildError(['app.blame'], BlameValueResolverInterface::class, BlameIdResolverInterface::class);
        $this->process($container);
    }

    public function testIdModeFailsOnAHostCurrentUserResolverRegisteredUnderItsId(): void
    {
        $container = $this->container('id');
        $container->register(CurrentUserResolverInterface::class);

        $this->expectBuildError([CurrentUserResolverInterface::class], CurrentUserResolverInterface::class, CurrentActorProviderInterface::class);
        $this->process($container);
    }

    public function testIdModeFailsOnDecoratorsOfALegacyPortAndNamesThemAll(): void
    {
        $container = $this->container('id');
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');
        $container->register('app.blame_decorator')->setDecoratedService(BlameValueResolverInterface::class);
        $container->register('app.optional_decorator')->setDecoratedService(BlameValueResolverInterface::class, null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);

        $this->expectBuildError(['app.blame', 'app.blame_decorator', 'app.optional_decorator'], BlameValueResolverInterface::class, BlameIdResolverInterface::class);
        $this->process($container);
    }

    public function testIdModeAcceptsDecoratorsOfTheUserPort(): void
    {
        $container = $this->container('id');
        $container->register('app.user_decorator')->setDecoratedService(CurrentUserResolverInterface::class);

        $this->process($container);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $container->getDefinition(AuditableEntityListener::class)->getArgument('$blameIdResolver'));
    }

    public function testIdModeAcceptsLegacyOverridesNextToOverriddenTwins(): void
    {
        $container = $this->container('id');
        $container->setAlias(BlameValueResolverInterface::class, 'app.blame');
        $container->setAlias(BlameIdResolverInterface::class, 'app.blame_id');
        $container->register(CurrentUserResolverInterface::class);
        $container->register('app.user_decorator')->setDecoratedService(CurrentUserResolverInterface::class);
        $container->setAlias(CurrentActorProviderInterface::class, 'app.actors');

        $this->process($container);
        $listener = $container->getDefinition(AuditableEntityListener::class);
        $this->assertEquals(new Reference(BlameIdResolverInterface::class), $listener->getArgument('$blameIdResolver'));
        $this->assertNull($listener->getArgument('$blameValueResolver'));
    }

    public function testIdModeWithoutTheLegacyIdsIsLeftAlone(): void
    {
        $container = $this->container('id');
        $container->removeAlias(BlameValueResolverInterface::class);
        $container->removeAlias(CurrentUserResolverInterface::class);

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

    /**
     * @param list<string> $services
     */
    private function expectBuildError(array $services, string $legacy, string $twin): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('With "nowo_audit_kit.blame_mode: id", the service(s) "%s" override "%s", which the id mode no longer supports since nowo-tech/audit-kit-bundle 2.0 (deprecated since 1.15). Alias "%s" to your service instead (see UPGRADING.md, "To 2.0.0").', implode('", "', $services), $legacy, $twin));
    }
}
