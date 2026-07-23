<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Integration tests: bundle extension loads services from default configuration.
 *
 * @covers \Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension
 */
final class AuditKitBundleIntegrationTest extends TestCase
{
    public function testExtensionLoadsDefaultConfiguration(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        self::assertTrue($container->hasParameter('nowo_audit_kit.user_class'));
        self::assertSame('App\\Entity\\User', $container->getParameter('nowo_audit_kit.user_class'));
        self::assertTrue($container->hasDefinition(AuditableEntityListener::class));
    }

    public function testBundleRegistersExtensionAlias(): void
    {
        $bundle    = new NowoAuditKitBundle();
        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(NowoAuditKitExtension::class, $extension);
        self::assertSame('nowo_audit_kit', $extension->getAlias());
    }
}
