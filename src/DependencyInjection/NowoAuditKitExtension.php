<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\DependencyInjection;

use InvalidArgumentException;
use Monolog\LogRecord;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Blame\UserIdBlameValueResolver;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Nowo\AuditKitBundle\Collation\ActionLogExactKeyColumns;
use Nowo\AuditKitBundle\Correlation\CorrelationConsoleSubscriber;
use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\CorrelationRequestSubscriber;
use Nowo\AuditKitBundle\Correlation\HttpClient\CorrelationHttpClient;
use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
use Nowo\AuditKitBundle\Correlation\Mailer\CorrelationMailerListener;
use Nowo\AuditKitBundle\Correlation\Messenger\CorrelationMiddleware;
use Nowo\AuditKitBundle\Correlation\Monolog\CorrelationLogProcessor;
use Nowo\AuditKitBundle\Doctrine\AuditableDoctrineListener;
use Nowo\AuditKitBundle\Doctrine\AuditableEntityListener;
use Nowo\AuditKitBundle\Model\AuditUserInterface;
use Nowo\AuditKitBundle\Profile\ProfileRegistry;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function class_exists;
use function interface_exists;
use function is_array;
use function is_string;
use function ltrim;
use function sprintf;

/** Loads bundle parameters and services from processed configuration. */
final class NowoAuditKitExtension extends Extension implements PrependExtensionInterface
{
    /** Doctrine mapping name registered for {@see AbstractActionLog}. */
    public const ACTION_LOG_MAPPING = 'NowoAuditKitActionLog';

    /** Since 2.2: fallback `CorrelationIdProviderInterface` service (see {@see Compiler\CorrelationProviderPass}). */
    public const NULL_CORRELATION_PROVIDER = 'nowo_audit_kit.correlation.null_provider';

    /** Since 2.2: Messenger middleware id to add to a bus `middleware` list. */
    public const MESSENGER_MIDDLEWARE = 'nowo_audit_kit.correlation.messenger_middleware';

    /** Since 2.2: tag of the timeline sources merged by {@see TimelineMerger}. */
    public const TIMELINE_SOURCE_TAG = 'nowo_audit_kit.timeline_source';

    /** Since 2.2: Monolog 3 record class (the log processor is registered only when it exists). */
    public const MONOLOG_RECORD_CLASS = LogRecord::class;

    /** Since 2.2: Mailer event (the mailer listener is registered only when it exists). */
    public const MAILER_MESSAGE_EVENT = MessageEvent::class;

    /** Since 2.2: Messenger middleware interface (the middleware is registered only when it exists). */
    public const MESSENGER_MIDDLEWARE_INTERFACE = MiddlewareInterface::class;

    /** Maps {@see AuditUserInterface} (BlameableTrait association target) to the default profile user class. */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $this->prependActionLogMapping($config['action_log'], $container);

        $userClass = $config['profiles'][$this->resolveDefaultProfileName($config)]['user_class'] ?? null;
        if (!is_string($userClass) || $userClass === '') {
            $userClass = $this->findAuthKitUserClass($container);
        }

        // Since 1.14 user_class may be an interface (blame_mode "id"): an interface is not a Doctrine target.
        if ($userClass === null || interface_exists($userClass)) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => [
                    AuditUserInterface::class => $userClass,
                ],
            ],
        ]);
    }

    /** Registers parameters and loads service definitions from YAML. */
    /**
     * REQ-SEC-006: declare actor_id / subject_id of every id-mode action log, so a host's
     * nowo:migrations:check-key-collations asserts their binary NO PAD collation against its real schema. The
     * contract comes from nowo-tech/migrations-kit-contracts (interfaces and value objects, no dependencies beyond
     * PHP); the check itself lives in MigrationsKitBundle, which only the host needs. A host without it simply never
     * reads the tag.
     */
    private function registerExactKeyColumns(ContainerBuilder $container): void
    {
        $container->register(ActionLogExactKeyColumns::class, ActionLogExactKeyColumns::class)
            ->setArguments([new Reference('doctrine')])
            ->addTag('nowo_migrations_kit.exact_key_columns');
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $defaultProfileName = $this->resolveDefaultProfileName($config);
        if (!isset($config['profiles'][$defaultProfileName])) {
            throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.default_profile" value "%s" does not match any configured profile.', $defaultProfileName));
        }

        $profiles = $config['profiles'];
        $this->resolveMissingUserClasses($profiles, $defaultProfileName, $container, $config['blame_mode']);
        $this->assertUniqueUserClasses($profiles);

        $defaultProfile = $profiles[$defaultProfileName];

        $container->setParameter('nowo_audit_kit.default_profile', $defaultProfileName);
        $container->setParameter('nowo_audit_kit.profiles', $profiles);
        $container->setParameter('nowo_audit_kit.enabled', $defaultProfile['enabled']);
        $container->setParameter('nowo_audit_kit.user_class', $defaultProfile['user_class']);
        $container->setParameter('nowo_audit_kit.fields', $defaultProfile['fields']);
        $container->setParameter('nowo_audit_kit.timestamp_type', $defaultProfile['timestamp_type']);
        $container->setParameter('nowo_audit_kit.blameable', $defaultProfile['blameable']);
        $container->setParameter('nowo_audit_kit.timestampable', $defaultProfile['timestampable']);
        $container->setParameter('nowo_audit_kit.auditable_entities', $this->auditableEntities($config['auditable_entities']));
        $container->setParameter('nowo_audit_kit.attribute_opt_in', $config['attribute_opt_in']);
        $container->setParameter('nowo_audit_kit.blame_mode', $config['blame_mode']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if ($config['blame_mode'] === 'id') {
            // Kept for hosts that type-hint the 1.14 port; the listener uses the id-only ports below (since 1.15).
            $container->setAlias(BlameValueResolverInterface::class, UserIdBlameValueResolver::class);

            // Since 1.15: the framework-free ports drive id mode. No security user, no ObjectManager; the host can
            // alias CurrentActorProviderInterface / BlameIdResolverInterface to its own services.
            // Since 2.0 BlamePortsPass fails the build on a host override of the 1.14 ports that the id mode no longer reads.
            $container->getDefinition(AuditableEntityListener::class)
                ->setArgument('$currentUserResolver', null)
                ->setArgument('$blameValueResolver', null)
                ->setArgument('$blameIdResolver', new Reference(BlameIdResolverInterface::class));
        }

        $this->loadActionLog($config['action_log'], $container);
        $this->registerExactKeyColumns($container);
        $this->loadChanges($config['action_log']['changes'], $container);
        $this->loadCorrelation($config['correlation'], $container);
        $this->loadTimeline($container);

        $registry = new ProfileRegistry($profiles, $defaultProfileName);

        if (!$registry->hasEnabledProfile()) {
            $container->removeDefinition(AuditableEntityListener::class);
            $container->removeDefinition(AuditableDoctrineListener::class);
        }
    }

    /**
     * Since 1.13: the classes of `auditable_entities`, each an existing class or interface, once.
     *
     * @param list<string> $classes
     *
     * @return list<class-string>
     */
    private function auditableEntities(array $classes): array
    {
        $valid = [];
        foreach ($classes as $class) {
            $class = ltrim($class, '\\');
            if (!class_exists($class) && !interface_exists($class)) {
                throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.auditable_entities" entry "%s" is not an existing class or interface.', $class));
            }
            $valid[$class] = true;
        }

        return array_keys($valid);
    }

    /**
     * Resolves the default profile after every configuration file has been merged, so a later file
     * (e.g. `when@prod`) that only adds profiles does not override an explicit `default_profile`.
     *
     * @param array<string, mixed> $config
     */
    private function resolveDefaultProfileName(array $config): string
    {
        $name = $config['default_profile'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $first = is_array($config['profiles'] ?? null) ? array_key_first($config['profiles']) : null;

        return $first === null ? 'default' : (string) $first;
    }

    /**
     * Maps the {@see AbstractActionLog} mapped superclass (outside the host mapping paths) on the default entity
     * manager when the action log is enabled; `action_log.register_mapping: false` leaves it to the host.
     *
     * Since 1.14 `action_log.mapping: xml` registers the shipped XML mapping instead of the attributes.
     *
     * @param array{entity_class: ?string, register_mapping: bool, mapping: string} $config
     */
    private function prependActionLogMapping(array $config, ContainerBuilder $container): void
    {
        if (!is_string($config['entity_class']) || $config['entity_class'] === '' || !$config['register_mapping']) {
            return;
        }

        $xml = $config['mapping'] === 'xml';

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    self::ACTION_LOG_MAPPING => [
                        'type'      => $xml ? 'xml' : 'attribute',
                        'is_bundle' => false,
                        'dir'       => $xml ? self::actionLogXmlMappingDir() : __DIR__ . '/../ActionLog',
                        'prefix'    => 'Nowo\\AuditKitBundle\\ActionLog',
                    ],
                ],
            ],
        ]);
    }

    /** Since 1.14: directory of the XML mapping of the action log superclasses (for hosts mapping them themselves). */
    public static function actionLogXmlMappingDir(): string
    {
        return __DIR__ . '/../Resources/config/doctrine/action_log';
    }

    /**
     * Registers the action log recorder and purge command when `action_log.entity_class` is set.
     *
     * Since 2.0 `flush_mode` defaults to `isolated`; an explicit `host` keeps the 1.x behaviour (no probe, no notice).
     *
     * @param array{entity_class: ?string, register_mapping: bool, scope_context_key: ?string, capture_ip: bool, flush_mode: string, changes: array<string, mixed>, purge: array{older_than_days: int, batch_size: int, actions: list<string>}} $config
     */
    private function loadActionLog(array $config, ContainerBuilder $container): void
    {
        $entityClass = $config['entity_class'];
        $enabled     = is_string($entityClass) && $entityClass !== '';
        $container->setParameter('nowo_audit_kit.action_log.enabled', $enabled);
        if (!$enabled) {
            return;
        }

        if (!is_subclass_of($entityClass, AbstractActionLog::class) && !is_subclass_of($entityClass, AbstractIdActionLog::class)) {
            throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.action_log.entity_class" value "%s" must be an existing class extending "%s" or "%s".', $entityClass, AbstractActionLog::class, AbstractIdActionLog::class));
        }

        $container->setParameter('nowo_audit_kit.action_log.entity_class', $entityClass);
        $container->setParameter('nowo_audit_kit.action_log.scope_context_key', $config['scope_context_key']);
        $container->setParameter('nowo_audit_kit.action_log.capture_ip', $config['capture_ip']);
        $container->setParameter('nowo_audit_kit.action_log.flush_mode', $config['flush_mode']);
        $container->setParameter('nowo_audit_kit.action_log.purge.older_than_days', $config['purge']['older_than_days']);
        $container->setParameter('nowo_audit_kit.action_log.purge.batch_size', $config['purge']['batch_size']);
        $container->setParameter('nowo_audit_kit.action_log.purge.actions', $config['purge']['actions']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('action_log.yaml');
    }

    /**
     * Since 2.2: the configured {@see ChangeSetBuilder}.
     *
     * @param array{secret_fields: list<string>, secret_pattern: string, max_string_length: int, max_bytes: int} $config
     */
    private function loadChanges(array $config, ContainerBuilder $container): void
    {
        $container->register(ChangeSetBuilder::class, ChangeSetBuilder::class)
            ->setArguments([$config['secret_fields'], $config['secret_pattern'], $config['max_string_length'], $config['max_bytes']]);
    }

    /**
     * Since 2.2: correlation id services. The fallback provider (null) is always registered and bound by
     * {@see Compiler\CorrelationProviderPass} only when nothing else binds the interface; `provider: true` binds the
     * bundle's {@see CorrelationIdHolder} and registers its listeners. The Messenger middleware, the log processor and the
     * outgoing-request decorator exist only when their package is installed.
     *
     * @param array{provider: bool, header_name: string, inbound_headers: list<string>, inbound_trusted_proxies_only: bool, response_header_paths: list<string>, messenger_child_ids: bool, http_client: bool, mailer: bool, monolog: bool} $config
     */
    private function loadCorrelation(array $config, ContainerBuilder $container): void
    {
        $container->register(self::NULL_CORRELATION_PROVIDER, NullCorrelationIdProvider::class);
        $provider = new Reference(CorrelationIdProviderInterface::class);

        if ($config['provider']) {
            $container->register(CorrelationIdHolder::class, CorrelationIdHolder::class)
                ->addTag('kernel.reset', ['method' => 'reset']);
            $container->setAlias(CorrelationIdProviderInterface::class, CorrelationIdHolder::class);
            $container->setAlias(CorrelationContextProviderInterface::class, CorrelationIdHolder::class);
            $container->register(InboundCorrelationId::class, InboundCorrelationId::class)
                ->setArguments([$config['inbound_headers'], $config['inbound_trusted_proxies_only']]);
            $container->register(CorrelationRequestSubscriber::class, CorrelationRequestSubscriber::class)
                ->setArguments([new Reference(CorrelationIdHolder::class), new Reference(InboundCorrelationId::class), $config['response_header_paths'], $config['header_name']])
                ->addTag('kernel.event_subscriber');
            $container->register(CorrelationConsoleSubscriber::class, CorrelationConsoleSubscriber::class)
                ->setArguments([new Reference(CorrelationIdHolder::class)])
                ->addTag('kernel.event_subscriber');
        }

        if (interface_exists(self::MESSENGER_MIDDLEWARE_INTERFACE)) {
            $container->register(self::MESSENGER_MIDDLEWARE, CorrelationMiddleware::class)
                ->setArguments([$provider, $config['messenger_child_ids']]);
        }

        if ($config['monolog'] && class_exists(self::MONOLOG_RECORD_CLASS)) {
            $container->register(CorrelationLogProcessor::class, CorrelationLogProcessor::class)
                ->setArguments([$provider])
                ->addTag('monolog.processor');
        }

        if ($config['mailer'] && class_exists(self::MAILER_MESSAGE_EVENT)) {
            $container->register(CorrelationMailerListener::class, CorrelationMailerListener::class)
                ->setArguments([$provider, $config['header_name']])
                ->addTag('kernel.event_listener', ['event' => self::MAILER_MESSAGE_EVENT, 'priority' => 255]);
        }

        // Without symfony/http-client there is no `http_client` service and the decorator is dropped.
        if ($config['http_client']) {
            $container->register(CorrelationHttpClient::class, CorrelationHttpClient::class)
                ->setDecoratedService('http_client', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
                ->setArguments([new Reference('.inner'), $provider, $config['header_name']]);
        }
    }

    /** Since 2.2: the {@see TimelineMerger} over every service tagged {@see self::TIMELINE_SOURCE_TAG}. */
    private function loadTimeline(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(TimelineSourceInterface::class)->addTag(self::TIMELINE_SOURCE_TAG);
        $container->register(TimelineMerger::class, TimelineMerger::class)
            ->setArguments([new TaggedIteratorArgument(self::TIMELINE_SOURCE_TAG)]);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** Same fallback as {@see resolveMissingUserClasses()}, read from the raw AuthKit configuration at prepend time. */
    private function findAuthKitUserClass(ContainerBuilder $container): ?string
    {
        foreach (array_reverse($container->getExtensionConfig('nowo_auth_kit')) as $authConfig) {
            $candidate = $authConfig['user_class'] ?? null;
            if (!is_string($candidate) && is_array($authConfig['profiles'] ?? null)) {
                $profileName = $authConfig['default_profile'] ?? array_key_first($authConfig['profiles']);
                $candidate   = $authConfig['profiles'][$profileName]['user_class'] ?? null;
            }

            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function resolveMissingUserClasses(array &$profiles, string $defaultProfileName, ContainerBuilder $container, string $blameMode): void
    {
        if ('' === ($profiles[$defaultProfileName]['user_class'] ?? '') && $container->hasParameter('nowo_auth_kit.user_class')) {
            $profiles[$defaultProfileName]['user_class'] = $container->getParameter('nowo_auth_kit.user_class');
        }

        // Since 1.14: a profile that needs no entity reference (blame_mode "id", or no blame) matches any security user.
        foreach ($profiles as $name => $profile) {
            if ('' === ($profile['user_class'] ?? '') && ($blameMode === 'id' || $profile['blameable'] === false)) {
                $profiles[$name]['user_class'] = UserInterface::class;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function assertUniqueUserClasses(array $profiles): void
    {
        $seen = [];

        foreach ($profiles as $name => $profile) {
            if ('' === ($profile['user_class'] ?? '')) {
                throw new InvalidArgumentException(sprintf('The "nowo_audit_kit.profiles.%s.user_class" configuration value is required.', $name));
            }

            $userClass = $profile['user_class'];
            if (isset($seen[$userClass])) {
                throw new InvalidArgumentException(sprintf('Duplicate user_class "%s" in profiles "%s" and "%s".', $userClass, $seen[$userClass], $name));
            }

            $seen[$userClass] = $name;
        }
    }
}
