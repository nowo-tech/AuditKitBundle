<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel;

use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/** FrameworkBundle + TwigBundle kernel for the `@NowoAuditKitBundle` namespace (REQ-TWIG-001 / REQ-TWIG-002). */
final class TwigKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $projectDir, private readonly string $cacheKey)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new NowoAuditKitBundle();
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-twig-kernel/' . $this->cacheKey . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-audit-kit-twig-kernel/' . $this->cacheKey . '/log';
    }

    protected function configureContainer(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'secret'               => 'test',
            'test'                 => true,
            'http_method_override' => false,
            'php_errors'           => ['log' => true],
        ]);
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('nowo_audit_kit', [
            'user_class' => Entity\KernelUser::class,
        ]);
    }
}
