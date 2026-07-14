<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests;

use Nowo\TwigInspectorBundle\DependencyInjection\NowoTwigInspectorExtension;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * Tests for NowoTwigInspectorBundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class NowoTwigInspectorBundleTest extends TestCase
{
    private NowoTwigInspectorBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new NowoTwigInspectorBundle();
    }

    public function testGetContainerExtension(): void
    {
        $extension = $this->bundle->getContainerExtension();

        $this->assertInstanceOf(ExtensionInterface::class, $extension);
        $this->assertInstanceOf(NowoTwigInspectorExtension::class, $extension);
    }

    public function testGetContainerExtensionReturnsSameInstance(): void
    {
        $extension1 = $this->bundle->getContainerExtension();
        $extension2 = $this->bundle->getContainerExtension();

        $this->assertSame($extension1, $extension2);
    }

    public function testGetContainerExtensionAlias(): void
    {
        $extension = $this->bundle->getContainerExtension();

        $this->assertSame('nowo_twig_inspector', $extension->getAlias());
    }
}
