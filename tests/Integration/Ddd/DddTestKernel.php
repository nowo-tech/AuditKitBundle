<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Since 1.14: a strict DDD host. Every entity is mapped in XML (no Doctrine or bundle attribute in the domain), the
 * firewall user is not an entity, blame fields and the action log store user ids (`blame_mode: id`, no `user_class`,
 * `AbstractIdActionLog` with `action_log.mapping: xml`).
 */
final class DddTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('ddd', false);
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
        return sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel/log';
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
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm'  => [
                'mappings' => [
                    'DddDomain' => [
                        'type'      => 'xml',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/config/doctrine',
                        'prefix'    => 'Nowo\\AuditKitBundle\\Tests\\Integration\\Ddd\\Domain',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'blame_mode'         => 'id',
            'auditable_entities' => [Domain\Invoice::class],
            'fields'             => [
                'created_at' => 'createdAt',
                'updated_at' => 'updatedAt',
                'created_by' => 'createdBy',
                'updated_by' => 'updatedBy',
            ],
            'action_log' => [
                'entity_class' => Domain\AuditEntry::class,
                'mapping'      => 'xml',
            ],
        ]);
        $container->setAlias('test.action_log_id_recorder', ActionLogIdRecorderInterface::class)->setPublic(true);
        $container->setAlias('test.blame_value_resolver', BlameValueResolverInterface::class)->setPublic(true);

        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->setAlias(TokenStorageInterface::class, 'security.token_storage');
        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, 'clock');
    }
}
