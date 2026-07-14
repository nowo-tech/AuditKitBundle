<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\DependencyInjection;

use Nowo\TwigInspectorBundle\DependencyInjection\NowoTwigInspectorExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests for NowoTwigInspectorExtension.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class NowoTwigInspectorExtensionTest extends TestCase
{
    private NowoTwigInspectorExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new NowoTwigInspectorExtension();
    }

    public function testGetAlias(): void
    {
        $this->assertSame('nowo_twig_inspector', $this->extension->getAlias());
    }

    public function testLoad(): void
    {
        $container = new ContainerBuilder();

        // Should not throw any exception
        $this->extension->load([], $container);

        $this->assertTrue(true);
    }

    public function testLoadWithConfig(): void
    {
        $container = new ContainerBuilder();
        $configs = [
          [
            'some_config' => 'value',
          ],
        ];

        // Should not throw any exception even with config
        $this->extension->load($configs, $container);

        $this->assertTrue(true);
    }
}
