<?php

declare(strict_types=1);

namespace Nowo\TwigInspectorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration definition for Twig Inspector Bundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.com>
 * @copyright 2024 Nowo.tech
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nowo_twig_inspector');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('enabled_extensions')
                    ->info('List of template file extensions to inspect (e.g., [".html.twig", ".twig"])')
                    ->defaultValue(['.html.twig'])
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('excluded_templates')
                    ->info('List of template names or patterns to exclude from inspection')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('excluded_blocks')
                    ->info('List of block names or patterns to exclude from inspection')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()
                ->booleanNode('enable_metrics')
                    ->info('Enable collection of template usage metrics in DataCollector')
                    ->defaultTrue()
                ->end()
                ->booleanNode('optimize_output_buffering')
                    ->info('Skip output buffering when inspector is disabled (performance optimization)')
                    ->defaultTrue()
                ->end()
                ->scalarNode('cookie_name')
                    ->info('Name of the cookie used to enable/disable the inspector')
                    ->defaultValue('twig_inspector_is_active')
                ->end()
            ->end();

        return $treeBuilder;
    }
}

