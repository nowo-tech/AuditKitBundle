<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\AuditKitBundle\Blame\BlameIdResolverInterface;
use Nowo\AuditKitBundle\Blame\BlameValueResolverInterface;
use Nowo\AuditKitBundle\Security\CurrentActorProviderInterface;
use Nowo\AuditKitBundle\Security\CurrentUserResolverInterface;
use Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyAlias\HostBlameValueResolver;
use Nowo\AuditKitBundle\Tests\Fixtures\BlamePorts\LegacyDecorator\HostCurrentUserResolver;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\BlamePortsTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sprintf;

/**
 * Since 2.0 (owner decision Q6): with `blame_mode: id`, a host override of a 1.14 port that the id mode no longer reads
 * fails the container build, also when autodiscovery registered it through `#[AsAlias]` or `#[AsDecorator]`. Overriding
 * the framework-free twins works, and the twin is what writes the blame ids.
 */
final class BlamePortsKernelTest extends TestCase
{
    private ?BlamePortsTestKernel $kernel = null;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-blame-ports-kernel');
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function legacyOverrides(): iterable
    {
        yield '#[AsAlias] of BlameValueResolverInterface' => ['LegacyAlias', HostBlameValueResolver::class, BlameValueResolverInterface::class, BlameIdResolverInterface::class];
        yield '#[AsDecorator] of CurrentUserResolverInterface' => ['LegacyDecorator', HostCurrentUserResolver::class, CurrentUserResolverInterface::class, CurrentActorProviderInterface::class];
    }

    #[DataProvider('legacyOverrides')]
    public function testAnAutodiscoveredLegacyOverrideFailsTheBuild(string $case, string $service, string $legacy, string $twin): void
    {
        $this->kernel = new BlamePortsTestKernel($case);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('With "nowo_audit_kit.blame_mode: id", the service(s) "%s" override "%s", which the id mode no longer reads since nowo-tech/audit-kit-bundle 2.0 (deprecated since 1.15). Alias "%s" to your service instead (see UPGRADING.md, "To 2.0.0").', $service, $legacy, $twin));
        $this->kernel->boot();
    }

    public function testAutodiscoveredTwinsWriteTheBlameIds(): void
    {
        $this->kernel = new BlamePortsTestKernel('Twins');
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Invoice::class)]);

        $invoice = new Invoice('inv-1', 'Draft');
        $em->persist($invoice);
        $em->flush();

        $this->assertSame('host:u-1', $invoice->getCreatedBy());
        $this->assertSame('host:u-1', $invoice->getUpdatedBy());
    }
}
