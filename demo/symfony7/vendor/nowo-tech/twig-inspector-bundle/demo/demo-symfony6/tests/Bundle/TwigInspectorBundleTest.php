<?php

declare(strict_types=1);

namespace App\Tests\Bundle;

use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Tests for Twig Inspector Bundle integration.
 *
 * Verifies that the bundle is correctly registered and available.
 *
 * @covers \Nowo\TwigInspectorBundle\NowoTwigInspectorBundle
 */
final class TwigInspectorBundleTest extends TestCase
{
    /**
     * Tests that the bundle extends Symfony Bundle class.
     */
    public function testBundleExtendsSymfonyBundle(): void
    {
        $bundle = new NowoTwigInspectorBundle();

        $this->assertInstanceOf(Bundle::class, $bundle);
    }

    /**
     * Tests that the bundle has a container extension.
     */
    public function testBundleHasContainerExtension(): void
    {
        $bundle = new NowoTwigInspectorBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertNotNull($extension);
        $this->assertSame('nowo_twig_inspector', $extension->getAlias());
    }
}

