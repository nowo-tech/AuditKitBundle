<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_audit_kit';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Master switch for the auditing listener.')
                ->end()
                ->scalarNode('user_class')
                    ->info('FQCN used for createdBy / updatedBy references.')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->example('App\\Entity\\User')
                ->end()
                ->arrayNode('fields')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('created_at')->defaultValue('createdAt')->cannotBeEmpty()->end()
                        ->scalarNode('updated_at')->defaultValue('updatedAt')->cannotBeEmpty()->end()
                        ->scalarNode('created_by')->defaultValue('createdBy')->cannotBeEmpty()->end()
                        ->scalarNode('updated_by')->defaultValue('updatedBy')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->enumNode('timestamp_type')
                    ->values(['datetime_immutable', 'datetime'])
                    ->defaultValue('datetime_immutable')
                ->end()
                ->booleanNode('blameable')
                    ->defaultTrue()
                    ->info('When false, blame fields are not managed.')
                ->end()
                ->booleanNode('timestampable')
                    ->defaultTrue()
                    ->info('When false, timestamp fields are not managed.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
