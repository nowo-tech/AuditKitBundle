<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\Tests\DependencyInjection;

use Nowo\TwigInspectorBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * Tests for Configuration.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    private Configuration $configuration;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
        $this->processor = new Processor();
    }

    public function testGetConfigTreeBuilder(): void
    {
        $treeBuilder = $this->configuration->getConfigTreeBuilder();
        $this->assertNotNull($treeBuilder);
    }

    public function testDefaultConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        $this->assertSame(['.html.twig'], $config['enabled_extensions']);
        $this->assertSame([], $config['excluded_templates']);
        $this->assertSame([], $config['excluded_blocks']);
        $this->assertTrue($config['enable_metrics']);
        $this->assertTrue($config['optimize_output_buffering']);
        $this->assertSame('twig_inspector_is_active', $config['cookie_name']);
    }

    public function testCustomConfiguration(): void
    {
        $configs = [
            [
                'enabled_extensions' => ['.twig', '.xml.twig'],
                'excluded_templates' => ['admin/*', 'email/*'],
                'excluded_blocks' => ['javascript', 'head_*'],
                'enable_metrics' => false,
                'optimize_output_buffering' => false,
                'cookie_name' => 'custom_cookie',
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, $configs);

        $this->assertSame(['.twig', '.xml.twig'], $config['enabled_extensions']);
        $this->assertSame(['admin/*', 'email/*'], $config['excluded_templates']);
        $this->assertSame(['javascript', 'head_*'], $config['excluded_blocks']);
        $this->assertFalse($config['enable_metrics']);
        $this->assertFalse($config['optimize_output_buffering']);
        $this->assertSame('custom_cookie', $config['cookie_name']);
    }

    public function testPartialConfiguration(): void
    {
        $configs = [
            [
                'excluded_templates' => ['admin/*'],
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, $configs);

        // Should merge with defaults
        $this->assertSame(['.html.twig'], $config['enabled_extensions']);
        $this->assertSame(['admin/*'], $config['excluded_templates']);
        $this->assertSame([], $config['excluded_blocks']);
        $this->assertTrue($config['enable_metrics']);
        $this->assertTrue($config['optimize_output_buffering']);
        $this->assertSame('twig_inspector_is_active', $config['cookie_name']);
    }

    public function testMultipleConfigFiles(): void
    {
        $configs = [
            [
                'excluded_templates' => ['admin/*'],
            ],
            [
                'excluded_blocks' => ['javascript'],
            ],
        ];

        $config = $this->processor->processConfiguration($this->configuration, $configs);

        // Should merge both configs
        $this->assertSame(['admin/*'], $config['excluded_templates']);
        $this->assertSame(['javascript'], $config['excluded_blocks']);
    }
}

