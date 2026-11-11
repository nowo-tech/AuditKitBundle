<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Since 1.15: a strict DDD host on the framework-free ports only.
 *
 * - No Symfony Security at all (no token storage): the host aliases {@see CurrentActorProviderInterface} to
 *   {@see HostActorContext}; blame ids come from the default {@see BlameIdResolverInterface}.
 * - A dedicated `domain` entity manager on its own connection maps the XML domain and the action log superclass
 *   (`register_mapping: false`); the default entity manager maps nothing.
 * - References by id only (`blame_mode: id`, `AbstractIdActionLog`); the action log writes in `flush_mode: isolated`.
 */
final class DddPortsTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('dddports', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new NowoAuditKitBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-ddd-ports-kernel/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-ddd-ports-kernel/log';
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret'               => 'test',
            'test'                 => true,
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default' => ['driver' => 'pdo_sqlite', 'memory' => true],
                    'domain'  => ['driver' => 'pdo_sqlite', 'memory' => true],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default', 'mappings' => []],
                    'domain'  => [
                        'connection' => 'domain',
                        'mappings'   => [
                            'DddDomain' => [
                                'type'      => 'xml',
                                'is_bundle' => false,
                                'dir'       => __DIR__ . '/config/doctrine',
                                'prefix'    => 'Nowo\\AuditKitBundle\\Tests\\Integration\\Ddd\\Domain',
                            ],
                            NowoAuditKitExtension::ACTION_LOG_MAPPING => [
                                'type'      => 'xml',
                                'is_bundle' => false,
                                'dir'       => NowoAuditKitExtension::actionLogXmlMappingDir(),
                                'prefix'    => 'Nowo\\AuditKitBundle\\ActionLog',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'blame_mode'         => 'id',
            'auditable_entities' => [Domain\Invoice::class],
            'default_profile'    => 'default',
            'profiles'           => [
                'default' => [],
                'import'  => ['user_class' => Domain\ImportProcess::class, 'blameable' => false],
            ],
            'action_log' => [
                'entity_class'     => Domain\AuditEntry::class,
                'register_mapping' => false,
                'flush_mode'       => ActionLogRecorder::FLUSH_ISOLATED,
            ],
        ]);

        $container->register(HostActorContext::class)->setPublic(true);
        $container->setAlias(CurrentActorProviderInterface::class, HostActorContext::class);
        $container->setAlias('test.action_log_id_recorder', ActionLogIdRecorderInterface::class)->setPublic(true);

        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, 'clock');
    }
}
