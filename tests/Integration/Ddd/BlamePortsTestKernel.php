<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Ddd;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\DependencyInjection\NowoAuditKitExtension;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Since 2.0: a `blame_mode: id` host whose audit adapters are registered by autodiscovery (`#[AsAlias]`,
 * `#[AsDecorator]`) from one directory of `tests/Fixtures/BlamePorts` (`$case`), as an application's
 * `services.yaml` resource would. The Invoice of the DDD domain is audited on the default entity manager.
 */
final class BlamePortsTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $case)
    {
        parent::__construct('blameports' . $case, false);
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
        return sys_get_temp_dir() . '/nowo-audit-kit-blame-ports-kernel/cache/' . $this->case;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-blame-ports-kernel/log';
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
                    // The domain directory also maps the AuditEntry action log, a subclass of the shipped superclass.
                    NowoAuditKitExtension::ACTION_LOG_MAPPING => [
                        'type'      => 'xml',
                        'is_bundle' => false,
                        'dir'       => NowoAuditKitExtension::actionLogXmlMappingDir(),
                        'prefix'    => 'Nowo\\AuditKitBundle\\ActionLog',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_audit_kit', [
            'blame_mode'         => 'id',
            'auditable_entities' => [Domain\Invoice::class],
        ]);

        $prototype = (new Definition())->setAutowired(true)->setAutoconfigured(true);
        $fixtures  = __DIR__ . '/../../Fixtures/BlamePorts/' . $this->case;
        (new PhpFileLoader($container, new FileLocator($fixtures)))
            ->registerClasses($prototype, 'Nowo\\AuditKitBundle\\Tests\\Fixtures\\BlamePorts\\' . $this->case . '\\', $fixtures . '/*.php');

        $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        $container->setAlias(TokenStorageInterface::class, 'security.token_storage');
        $container->register('clock', CountingClock::class)->setArguments(['2026-01-01 10:00:00'])->setPublic(true);
        $container->setAlias(ClockInterface::class, 'clock');
    }
}
