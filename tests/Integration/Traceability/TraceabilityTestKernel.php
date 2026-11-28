<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Repository\KernelActionLogRepository;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Real kernel with the bundle's correlation provider on (`correlation.provider: true`), a traceable action log on
 * in-memory SQLite, a Messenger bus with the correlation middleware, a controller and a command that record rows.
 */
final class TraceabilityTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $correlation overrides of `nowo_audit_kit.correlation`
     * @param bool $hostProvider bind `CorrelationIdProviderInterface` to the host's {@see HostCorrelationProvider}
     */
    public function __construct(
        private readonly array $correlation = [],
        private readonly bool $hostProvider = false,
    ) {
        parent::__construct('trace' . substr(md5(serialize([$correlation, $hostProvider])), 0, 8), false);
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
        return sys_get_temp_dir() . '/nowo-audit-kit-trace-kernel/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-trace-kernel/log';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('trace_api', '/api/trace')->controller(TraceController::class);
        $routes->add('trace_page', '/page')->controller(TraceController::class);
        $routes->add('trace_notify', '/api/notify')->controller(NotifyController::class);
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret'               => 'test',
            'test'                 => true,
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
            'router'               => ['utf8' => true],
            'http_client'          => ['mock_response_factory' => HttpRecorder::class],
            'mailer'               => ['dsn' => 'null://null'],
            'messenger'            => [
                'default_bus' => 'trace.bus',
                'buses'       => ['trace.bus' => ['middleware' => [NowoAuditKitExtension::MESSENGER_MIDDLEWARE]]],
            ],
        ]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm'  => [
                'mappings' => [
                    'AuditKitTest' => [
                        'type'   => 'attribute',
                        'dir'    => __DIR__ . '/../Kernel/Entity',
                        'prefix' => 'Nowo\\AuditKitBundle\\Tests\\Integration\\Kernel\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'user_class'  => KernelUser::class,
            'action_log'  => ['entity_class' => KernelActionLog::class, 'flush_mode' => ActionLogRecorder::FLUSH_ISOLATED],
            'correlation' => $this->correlation + ['provider' => !$this->hostProvider, 'response_header_paths' => ['/api'], 'http_client' => true, 'mailer' => true],
        ]);

        $container->register(KernelActionLogRepository::class)
            ->setAutowired(true)
            ->addTag('doctrine.repository_service');
        $container->register(TraceController::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');
        $container->register(TraceCommand::class)
            ->setAutowired(true)
            ->addTag('console.command', ['command' => TraceCommand::NAME]);
        $container->register(TraceHandler::class)
            ->setPublic(true)
            ->setArguments([new Reference(CorrelationIdHolder::class)])
            ->addTag('messenger.message_handler', ['handles' => TraceMessage::class]);

        $container->register(HttpRecorder::class)->setPublic(true);
        $container->register(MailRecorder::class)
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => MessageEvent::class, 'priority' => -255]);
        $container->register(NotifyController::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');
        if ($this->hostProvider) {
            $container->register(HostCorrelationProvider::class)
                ->setPublic(true)
                ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => 4096])
                ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'reset', 'priority' => -4096])
                ->addTag('kernel.reset', ['method' => 'reset']);
            $container->setAlias(CorrelationIdProviderInterface::class, HostCorrelationProvider::class);
            $container->getDefinition(TraceHandler::class)->setArguments([new Reference(CorrelationIdProviderInterface::class)]);
        } else {
            $container->setAlias('test.correlation_holder', CorrelationIdHolder::class)->setPublic(true);
        }
        $container->setAlias('test.action_log_reader', ActionLogReaderInterface::class)->setPublic(true);
        $container->setAlias('test.timeline', TimelineMerger::class)->setPublic(true);
        $container->setAlias('test.bus', 'trace.bus')->setPublic(true);
        $container->setAlias('test.http_client', 'http_client')->setPublic(true);

        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00']);
        $container->setAlias(ClockInterface::class, 'clock');
        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->setAlias(TokenStorageInterface::class, 'security.token_storage');
    }
}
