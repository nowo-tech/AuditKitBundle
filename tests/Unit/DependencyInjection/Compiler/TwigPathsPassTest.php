<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleViewsPath(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[array_key_last($calls)][0]);
        self::assertSame('NowoAuditKitBundle', $calls[array_key_last($calls)][1][1]);
        self::assertStringEndsWith('/Resources/views', $calls[array_key_last($calls)][1][0]);
    }

    public function testNoOpWhenLoaderMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testPrependsApplicationOverrideWhenPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        $projectDir  = sys_get_temp_dir() . '/audit-kit-project-' . uniqid('', true);
        $overrideDir = $projectDir . '/templates/bundles/NowoAuditKitBundle';
        mkdir($overrideDir, 0777, true);
        $container->setParameter('kernel.project_dir', $projectDir);

        try {
            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
            self::assertSame('prependPath', $calls[0][0]);
            self::assertSame('NowoAuditKitBundle', $calls[0][1][1]);
            self::assertSame('addPath', $calls[array_key_last($calls)][0]);
            self::assertSame('NowoAuditKitBundle', $calls[array_key_last($calls)][1][1]);
        } finally {
            @rmdir($overrideDir);
            @rmdir($projectDir . '/templates/bundles');
            @rmdir($projectDir . '/templates');
            @rmdir($projectDir);
        }
    }

    public function testResolvesTheNativeLoaderAliasChain(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.filesystem', new Definition('stdClass'));
        $container->setAlias('twig.loader.chained', 'twig.loader.filesystem');
        $container->setAlias('twig.loader.native', 'twig.loader.chained');

        (new TwigPathsPass())->process($container);

        self::assertSame('addPath', $container->getDefinition('twig.loader.filesystem')->getMethodCalls()[0][0]);
    }

    public function testDanglingAliasFallsBackToTheFilesystemLoader(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('twig.loader.native', 'missing.loader');
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
    }

    public function testUsesTheNativeLoaderDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native', new Definition('stdClass'));
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/audit-kit-no-overrides');

        (new TwigPathsPass())->process($container);

        self::assertCount(1, $container->getDefinition('twig.loader.native')->getMethodCalls());
    }
}
