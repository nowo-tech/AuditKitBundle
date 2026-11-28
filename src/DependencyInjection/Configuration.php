<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
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
                    ->info('Since 1.13: when true, #[Auditable] alone (without a bundle interface or trait) opts a class in. Default true since 2.0 (announced in 1.13); set false to keep ignoring the attribute alone (the 1.x default).')
                    ->defaultTrue()
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
                ->arrayNode('correlation')
                    ->info('Since 2.2: correlation id (nowo-tech/correlation-contract). The action log, the log processor and the outgoing-request header read the CorrelationIdProviderInterface service: alias it to your provider, or set provider: true to use the bundle\'s.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('provider')
                            ->info('Register the bundle\'s provider (CorrelationIdHolder) and its listeners: one id per main request (inbound header or new), per console command and per received message. Off by default: hosts with a provider of their own alias it instead.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('inbound_headers')
                            ->info('With provider: true, request headers read for an incoming id, in order (traceparent gives its trace-id). [] never reads one.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(InboundCorrelationId::DEFAULT_HEADERS)
                        ->end()
                        ->booleanNode('inbound_trusted_proxies_only')
                            ->info('With provider: true, read the inbound headers only from trusted proxies (framework.trusted_proxies), so a client cannot choose the id its requests are recorded under.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('response_header_paths')
                            ->info('With provider: true, path prefixes whose responses carry X-Correlation-Id (none by default).')
                            ->example(['/api'])
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('messenger_child_ids')
                            ->info('With provider: true, a received message runs under a new id with caused_by = the dispatching id, instead of the dispatching id itself.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('http_client')
                            ->info('Decorate http_client so outgoing requests carry X-Correlation-Id (needs symfony/http-client).')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('monolog')
                            ->info('Add correlation_id / caused_by to Monolog records (when monolog/monolog 3 is installed).')
                            ->defaultTrue()
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
                            ->info('Since 1.15: what recordAndFlush() / recordIdsAndFlush() flush. "isolated" (default since 2.0, REQ-ARCH-001 rule 6): only the new row, through a short-lived entity manager of the bundle on the same connection; the application unit of work is neither flushed nor cleared, so your pending changes stay pending. "host" (the 1.x default, kept as an explicit choice): the entity manager that maps the action log class, with every pending change of the application.')
                            ->values([ActionLogRecorder::FLUSH_HOST, ActionLogRecorder::FLUSH_ISOLATED])
                            ->defaultValue(ActionLogRecorder::FLUSH_ISOLATED)
                        ->end()
                        ->arrayNode('changes')
                            ->info('Since 2.2: how ChangeSetBuilder masks and bounds the before -> after changes stored in the `changes` column.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('secret_fields')
                                    ->info('Field names always stored as secrets ({changed: true} only), whatever the policy says (case-insensitive).')
                                    ->example(['iban', 'smtp_login'])
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->defaultValue([])
                                ->end()
                                ->scalarNode('secret_pattern')
                                    ->info('Regular expression of secret-looking field names: masked without a policy, refused as "plain" in a policy, masked as nested array keys.')
                                    ->defaultValue(ChangeSetBuilder::DEFAULT_SECRET_PATTERN)
                                    ->cannotBeEmpty()
                                ->end()
                                ->integerNode('max_string_length')
                                    ->info('Strings longer than this (characters) are stored as their lengths only.')
                                    ->defaultValue(ChangeSetBuilder::DEFAULT_MAX_STRING_LENGTH)
                                    ->min(1)
                                ->end()
                                ->integerNode('max_bytes')
                                    ->info('Largest JSON of one change set; fields beyond it are cut field by field.')
                                    ->defaultValue(ChangeSetBuilder::DEFAULT_MAX_BYTES)
                                    ->min(64)
                                ->end()
                            ->end()
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
