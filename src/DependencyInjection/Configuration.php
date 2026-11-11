<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
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

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is resolved from the authenticated user. Defaults to the first configured profile (resolved after all configuration files are merged).')
                    ->defaultNull()
                ->end()
                ->enumNode('blame_mode')
                    ->info('Since 1.14: what the blame fields receive. "reference" (default): a Doctrine reference to the profile user_class entity (BlameableTrait relations). "id": the current user id as a string (getId(), never the identifier), for hosts whose blame fields are plain id columns; user_class becomes optional (any security user, or a class / interface to match).')
                    ->values(['reference', 'id'])
                    ->defaultValue('reference')
                ->end()
                ->booleanNode('attribute_opt_in')
                    ->info('Since 1.13: when true, #[Auditable] alone (without a bundle interface or trait) opts a class in. Default false keeps the 1.12 behaviour (the attribute alone is ignored); the default becomes true in 2.0.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('auditable_entities')
                    ->info('Since 1.13: entity classes (or parent classes / interfaces of your own) audited without any bundle interface or trait. Only the configured fields that exist and accept the value are written (others are skipped with a warning). #[Auditable(enabled: false)] opts out.')
                    ->example(['App\\Entity\\Invoice', 'App\\Domain\\AuditedRecord'])
                    ->scalarPrototype()->cannotBeEmpty()->end()
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
                                ->info('FQCN used for createdBy / updatedBy references (required with blame_mode "reference" and blameable true). Since 1.14 optional with blame_mode "id" or blameable false, and may be an interface: the profile then matches security users implementing it (default Symfony UserInterface).')
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
                ->arrayNode('action_log')
                    ->info('Opt-in action log (who did what, to whom). Enabled when entity_class is set.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_class')
                            ->info('Host entity extending Nowo\\AuditKitBundle\\ActionLog\\AbstractActionLog (user relations) or, since 1.14, AbstractIdActionLog (user id columns, no foreign key). Null disables the action log services.')
                            ->defaultNull()
                            ->example('App\\Entity\\UserAction')
                        ->end()
                        ->booleanNode('register_mapping')
                            ->info('Prepend the Doctrine ORM mapping of the action log superclasses on the default entity manager. Disable to map them yourself (several entity managers).')
                            ->defaultTrue()
                        ->end()
                        ->enumNode('mapping')
                            ->info('Since 1.14: driver of the prepended superclass mapping. "attribute" (default) or "xml" (Resources/config/doctrine/action_log/*.orm.xml) for hosts that map everything in XML.')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                        ->end()
                        ->scalarNode('scope_context_key')
                            ->info('Context key copied into `scope` by ActionLogRecorder when no scope is passed (e.g. project_uuid).')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('capture_ip')
                            ->info('Store the client IP of the current request.')
                            ->defaultTrue()
                        ->end()
                        ->enumNode('flush_mode')
                            ->info('Since 1.15: what recordAndFlush() / recordIdsAndFlush() flush. "host" (default, the behaviour of earlier versions): the entity manager that maps the action log class, with every pending change of the application. "isolated": only the new row, through a short-lived entity manager of the bundle on the same connection; the application unit of work is neither flushed nor cleared.')
                            ->values([ActionLogRecorder::FLUSH_HOST, ActionLogRecorder::FLUSH_ISOLATED])
                            ->defaultValue(ActionLogRecorder::FLUSH_HOST)
                        ->end()
                        ->arrayNode('purge')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('older_than_days')
                                    ->info('Default --older-than-days of nowo:audit-kit:action-log:purge.')
                                    ->defaultValue(90)
                                    ->min(1)
                                ->end()
                                ->integerNode('batch_size')
                                    ->info('Rows deleted per keyset batch.')
                                    ->defaultValue(1000)
                                    ->min(1)
                                ->end()
                                ->arrayNode('actions')
                                    ->info('Actions purged when the command gets no --action (empty: --action or --all-actions required).')
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->defaultValue([])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
