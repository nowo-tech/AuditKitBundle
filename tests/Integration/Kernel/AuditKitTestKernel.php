<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Real kernel (FrameworkBundle + DoctrineBundle on in-memory SQLite) for end-to-end persistence tests.
 * Since 1.15 `$flushMode` sets `action_log.flush_mode` (one container per mode). Since 1.17 `$databaseUrl` points the
 * kernel at a real database instead (tests/Database, REQ-RUNTIME-003). A null `$flushMode` leaves
 * `action_log.flush_mode` unset (since 2.0: the `isolated` default), and `$transactionDoctrineBundle` registers
 * transaction-doctrine's Symfony bundle. `$attributeOptIn` null leaves `attribute_opt_in` unset (since 2.0: `true`).
 */
final class AuditKitTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(
        private readonly ?string $flushMode = ActionLogRecorder::FLUSH_HOST,
        private readonly ?string $databaseUrl = null,
        private readonly bool $transactionDoctrineBundle = false,
        private readonly ?bool $attributeOptIn = true,
    ) {
        parent::__construct(($flushMode === ActionLogRecorder::FLUSH_HOST ? 'test' : 'test' . ($flushMode ?? 'unset')) . ($transactionDoctrineBundle ? 'td' : '') . ($databaseUrl === null ? '' : 'db') . match ($attributeOptIn) {
            true  => '',
            false => 'optout',
            null  => 'optunset',
        }, false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new NowoAuditKitBundle();
        if ($this->transactionDoctrineBundle) {
            yield new NowoTransactionDoctrineBundle();
        }
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-test-kernel/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-test-kernel/log';
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
                    'AuditKitTest' => [
                        'type'   => 'attribute',
                        'dir'    => __DIR__ . '/Entity',
                        'prefix' => 'Nowo\\AuditKitBundle\\Tests\\Integration\\Kernel\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'user_class'         => Entity\KernelUser::class,
            'auditable_entities' => [Entity\KernelAuditedRecord::class],
            'action_log'         => [
                'entity_class'      => Entity\KernelActionLog::class,
                'scope_context_key' => 'project_uuid',
                'purge'             => ['older_than_days' => 30, 'batch_size' => 2, 'actions' => ['item.viewed']],
            ] + ($this->flushMode === null ? [] : ['flush_mode' => $this->flushMode]),
        ] + ($this->attributeOptIn === null ? [] : ['attribute_opt_in' => $this->attributeOptIn]));
        $container->register(Repository\KernelActionLogRepository::class)
            ->setAutowired(true)
            ->addTag('doctrine.repository_service');
        $container->setAlias('test.action_log_recorder', ActionLogRecorder::class)->setPublic(true);
        $container->setAlias('test.action_log_reader', ActionLogReaderInterface::class)->setPublic(true);

        // No SecurityBundle in this kernel: provide the token storage CurrentUserResolver depends on.
        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->setAlias(TokenStorageInterface::class, 'security.token_storage');
        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, 'clock');
    }
}
