<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** Defines the {@see Configuration::ALIAS} configuration tree for audit field options. */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_audit_kit';

    /** Builds the validated configuration schema for the bundle extension. */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->beforeNormalization()
                ->always()
                ->then(static function (?array $config): array {
                    $config ??= [];

                    if (!isset($config['profiles'])) {
                        $config['profiles'] = [
                            'default' => [
                                'enabled'        => $config['enabled'] ?? true,
                                'user_class'     => $config['user_class'] ?? null,
                                'fields'         => $config['fields'] ?? [],
                                'timestamp_type' => $config['timestamp_type'] ?? 'datetime_immutable',
                                'blameable'      => $config['blameable'] ?? true,
                                'timestampable'  => $config['timestampable'] ?? true,
                            ],
                        ];
                        unset(
                            $config['enabled'],
                            $config['user_class'],
                            $config['fields'],
                            $config['timestamp_type'],
                            $config['blameable'],
                            $config['timestampable'],
                        );
                    }

                    if (!isset($config['default_profile'])) {
                        $profileNames              = array_keys($config['profiles']);
                        $config['default_profile'] = $profileNames[0] ?? 'default';
                    }

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is resolved from the authenticated user.')
                    ->defaultValue('default')
                ->end()
                ->arrayNode('profiles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')
                                ->defaultTrue()
                                ->info('Enable auditing for this profile.')
                            ->end()
                            ->scalarNode('user_class')
                                ->info('FQCN used for createdBy / updatedBy references.')
                                ->defaultNull()
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
                                ->info('When false, blame fields are not managed for this profile.')
                            ->end()
                            ->booleanNode('timestampable')
                                ->defaultTrue()
                                ->info('When false, timestamp fields are not managed for this profile.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
