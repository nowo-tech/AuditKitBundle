<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function md5;

/**
 * Since 2.0 (lesson of the wave-1 majors): a host whose action log rows reference host entities, set by a host Doctrine
 * listener on the shared event manager, recorded with the default `flush_mode` (isolated) or an explicit one. In-memory
 * SQLite, or `$databaseUrl` (tests/Database).
 */
final class HostRelationsTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ?string $flushMode = null,
        private readonly ?string $databaseUrl = null,
    ) {
        parent::__construct('hr' . md5($entityClass . '|' . ($flushMode ?? 'unset') . '|' . ($databaseUrl ?? '')), false);
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
        return sys_get_temp_dir() . '/nowo-audit-kit-host-relations/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-host-relations/log';
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
            'dbal' => $this->databaseUrl === null ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['url' => $this->databaseUrl],
            'orm'  => [
                'mappings' => [
                    'HostRelations' => [
                        'type'   => 'attribute',
                        'dir'    => __DIR__,
                        'prefix' => __NAMESPACE__,
                    ],
                    'KernelUser' => [
                        'type'   => 'attribute',
                        'dir'    => __DIR__ . '/../../Integration/Kernel/Entity',
                        'prefix' => 'Nowo\\AuditKitBundle\\Tests\\Integration\\Kernel\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'user_class' => KernelUser::class,
            'action_log' => ['entity_class' => $this->entityClass, 'capture_ip' => false]
                + ($this->flushMode === null ? [] : ['flush_mode' => $this->flushMode]),
        ]);
        $container->register(TenantStampingListener::class)
            ->setPublic(true)
            ->addTag('doctrine.event_listener', ['event' => 'prePersist']);
        $container->setAlias('test.action_log_recorder', ActionLogRecorder::class)->setPublic(true);

        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->setAlias(TokenStorageInterface::class, 'security.token_storage');
        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, 'clock');
    }
}
