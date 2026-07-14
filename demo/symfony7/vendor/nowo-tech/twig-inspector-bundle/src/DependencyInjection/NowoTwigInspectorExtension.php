<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Extension class that loads and manages the TwigInspector bundle configuration.
 * Handles service definitions and configuration processing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class NowoTwigInspectorExtension extends Extension
{
    /**
     * Loads the services configuration and processes the bundle configuration.
     * Loads the services.yaml file from the bundle's Resources/config directory.
     *
     * @param array<string, mixed> $configs   Array of configuration values
     * @param ContainerBuilder     $container The container builder object
     *
     * @return void
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $processor = new Processor();
        $configuration = new Configuration();
        $config = $processor->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Set configuration parameters
        $container->setParameter('nowo_twig_inspector.enabled_extensions', $config['enabled_extensions']);
        $container->setParameter('nowo_twig_inspector.excluded_templates', $config['excluded_templates']);
        $container->setParameter('nowo_twig_inspector.excluded_blocks', $config['excluded_blocks']);
        $container->setParameter('nowo_twig_inspector.enable_metrics', $config['enable_metrics']);
        $container->setParameter('nowo_twig_inspector.optimize_output_buffering', $config['optimize_output_buffering']);
        $container->setParameter('nowo_twig_inspector.cookie_name', $config['cookie_name']);

        // Pass configuration to HtmlCommentsExtension
        $htmlCommentsExtensionDefinition = $container->getDefinition('Nowo\TwigInspectorBundle\Twig\HtmlCommentsExtension');
        // Set additional arguments after the base ones
        $htmlCommentsExtensionDefinition->setArgument(3, $config['enabled_extensions']);
        $htmlCommentsExtensionDefinition->setArgument(4, $config['excluded_templates']);
        $htmlCommentsExtensionDefinition->setArgument(5, $config['excluded_blocks']);
        $htmlCommentsExtensionDefinition->setArgument(6, $config['cookie_name']);

        // Pass configuration to DataCollector
        $collectorDefinition = $container->getDefinition('Nowo\TwigInspectorBundle\DataCollector\TwigInspectorCollector');
        $collectorDefinition->setArgument(0, new Reference('request_stack'));
    }

    /**
     * Returns the alias name of the extension.
     * This alias is used in configuration files to reference this extension.
     *
     * @return string The alias name of the extension (nowo_twig_inspector)
     */
    public function getAlias(): string
    {
        return 'nowo_twig_inspector';
    }
}
