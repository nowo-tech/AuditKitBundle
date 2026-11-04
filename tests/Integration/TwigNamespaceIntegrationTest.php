<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Nowo\AuditKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\TwigKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** REQ-TWIG-001 / REQ-TWIG-002: `@NowoAuditKitBundle` is registered and application overrides win. */
final class TwigNamespaceIntegrationTest extends TestCase
{
    private const TIMELINE = '@' . TwigPathsPass::TWIG_NAMESPACE . '/action_log/_timeline.html.twig';

    private ?TwigKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-twig-kernel');
    }

    public function testBundleTemplatesResolveUnderTheNowoAuditKitBundleNamespace(): void
    {
        $twig = $this->boot(__DIR__ . '/Kernel', 'vendor');

        $this->assertTrue($twig->getLoader()->exists(self::TIMELINE));
        $this->assertSame(
            realpath(__DIR__ . '/../../src/Resources/views/action_log/_timeline.html.twig'),
            realpath($twig->getLoader()->getSourceContext(self::TIMELINE)->getPath()),
        );
    }

    public function testApplicationOverrideWinsOverTheBundleTemplate(): void
    {
        $twig = $this->boot(__DIR__ . '/Kernel/TwigOverrideProject', 'override');

        $loader = $twig->getLoader();
        $this->assertInstanceOf(FilesystemLoader::class, $loader);
        $paths = array_map(static fn (string $path): string => (string) realpath($path), $loader->getPaths(TwigPathsPass::TWIG_NAMESPACE));
        $this->assertSame((string) realpath(__DIR__ . '/Kernel/TwigOverrideProject/templates/bundles/NowoAuditKitBundle'), $paths[0]);
        $this->assertSame((string) realpath(__DIR__ . '/../../src/Resources/views'), $paths[array_key_last($paths)]);
        $this->assertStringContainsString('application timeline override', $twig->render(self::TIMELINE, ['entries' => []]));
    }

    public function testTheTimelineRendersWithTheDefaultNullAvatarResolver(): void
    {
        $twig     = $this->boot(__DIR__ . '/Kernel', 'avatars');
        $user     = new KernelUser('ada@example.com');
        $user->id = 1;

        $html = $twig->render(self::TIMELINE, ['entries' => [(new KernelActionLog())->setAction('x')->setActor($user)]]);

        $this->assertStringContainsString('data-testid="action-log-entry"', $html);
        $this->assertStringNotContainsString('nowo-ui-avatar', $html);
        $this->assertSame('', $twig->createTemplate('{{ nowo_audit_kit_avatar_url(user) }}')->render(['user' => $user]));
    }

    private function boot(string $projectDir, string $cacheKey): Environment
    {
        $this->kernel = new TwigKernel($projectDir, $cacheKey);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);
        $twig = $container->get('twig');
        $this->assertInstanceOf(Environment::class, $twig);

        return $twig;
    }
}
