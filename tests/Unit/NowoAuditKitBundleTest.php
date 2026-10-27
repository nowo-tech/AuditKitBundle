<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoAuditKitBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        $bundle = new NowoAuditKitBundle();
        $this->assertSame('nowo_audit_kit', $bundle->getContainerExtension()->getAlias());
    }

    public function testBuildRegistersTheTwigPathsPass(): void
    {
        $container = new ContainerBuilder();
        (new NowoAuditKitBundle())->build($container);

        $passes = array_filter(
            $container->getCompilerPassConfig()->getPasses(),
            static fn (object $pass): bool => $pass instanceof TwigPathsPass,
        );
        $this->assertCount(1, $passes);
    }
}
