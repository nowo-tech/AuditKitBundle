<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Security\CurrentUserResolver;
use Nowo\AuditKitBundle\Security\SecurityCurrentActorProvider;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddPortsTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\Invoice;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\HostActorContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Since 1.15, REQ-ARCH-001 DDD host on the framework-free ports only: no Symfony Security, a dedicated entity
 * manager, references by id, the host's {@see HostActorContext} as the single audit adapter. Real kernel, SQLite.
 */
final class DddPortsHostIntegrationTest extends TestCase
{
    private const ALICE = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';
    private const BOB   = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f61';

    private DddPortsTestKernel $kernel;

    private EntityManagerInterface $domain;

    private HostActorContext $actors;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-ddd-ports-kernel');

        $this->kernel = new DddPortsTestKernel();
        $this->kernel->boot();

        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $domain = $registry->getManager('domain');
        self::assertInstanceOf(EntityManagerInterface::class, $domain);
        $this->domain = $domain;
        (new SchemaTool($this->domain))->createSchema($this->domain->getMetadataFactory()->getAllMetadata());

        $actors = $this->kernel->getContainer()->get(HostActorContext::class);
        self::assertInstanceOf(HostActorContext::class, $actors);
        $this->actors = $actors;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testBootsWithoutSymfonySecurityAndWithoutTheSecurityAdapters(): void
    {
        $container = $this->kernel->getContainer();

        $this->assertFalse($container->has('security.token_storage'));
        $this->assertFalse($container->has(CurrentUserResolver::class));
        $this->assertFalse($container->has(SecurityCurrentActorProvider::class));
        $this->assertSame('id', $container->getParameter('nowo_audit_kit.blame_mode'));
        $this->assertSame(ActionLogRecorder::FLUSH_ISOLATED, $container->getParameter('nowo_audit_kit.action_log.flush_mode'));

        // The dedicated entity manager maps the domain; the default one maps nothing of it.
        $default = $container->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $default);
        $this->assertSame([], $default->getMetadataFactory()->getAllMetadata());
        $this->assertSame([], $default->getConnection()->createSchemaManager()->listTableNames());
        $this->assertSame([], $this->domain->getClassMetadata(AuditEntry::class)->getAssociationNames());
    }

    public function testBlameAndProfileComeFromTheHostActorPort(): void
    {
        $this->actors->actAs(self::ALICE);
        $invoice = new Invoice('inv-1', 'Draft');
        $this->domain->persist($invoice);
        $this->domain->flush();

        $this->actors->actAs(self::BOB);
        $invoice->rename('Final');
        $this->domain->flush();

        $this->actors->actAs('job-7', 'import');
        $this->domain->persist(new Invoice('inv-2', 'Imported'));
        $this->domain->flush();

        $this->actors->actAs(null);
        $this->domain->persist(new Invoice('inv-3', 'Anonymous'));
        $this->domain->flush();

        $rows = $this->domain->getConnection()->fetchAllAssociativeIndexed('SELECT id, created_by, updated_by, created_at FROM ddd_invoice ORDER BY id');
        $this->assertSame(self::ALICE, $rows['inv-1']['created_by']);
        $this->assertSame(self::BOB, $rows['inv-1']['updated_by']);
        $this->assertNull($rows['inv-2']['created_by'], 'import profile: blameable false');
        $this->assertNotNull($rows['inv-2']['created_at'], 'import profile: timestamps still set');
        $this->assertNull($rows['inv-3']['created_by']);
        $this->assertNotNull($rows['inv-3']['created_at']);
    }

    /** Kept-alive kernel (REQ-RUNTIME-002): alternating actors never inherit the previous actor's id. */
    public function testAlternatingActorsOnOneKernelNeverLeak(): void
    {
        $actors = [[self::ALICE, null, self::ALICE], [self::BOB, null, self::BOB], ['job-7', 'import', null], [null, null, null]];

        for ($i = 0; $i < 200; ++$i) {
            [$id, $profile, $expected] = $actors[$i % 4];
            $this->actors->actAs($id, $profile);
            $this->domain->persist(new Invoice('inv-' . $i, 'Loop'));
            $this->domain->flush();
            $this->domain->clear();

            $this->assertSame($expected, $this->domain->getConnection()->fetchOne('SELECT created_by FROM ddd_invoice WHERE id = ?', ['inv-' . $i]), 'iteration ' . $i);
        }
    }

    public function testIsolatedActionLogOnTheDedicatedManagerLeavesHostChangesPending(): void
    {
        $recorder = $this->kernel->getContainer()->get('test.action_log_id_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);

        $this->actors->actAs(self::ALICE);
        $pending = new Invoice('inv-pending', 'Pending');
        $this->domain->persist($pending);

        $entry = $recorder->recordIdsAndFlush('invoice.sent', self::ALICE, self::BOB, ['invoice' => 'inv-pending']);

        $this->assertInstanceOf(AuditEntry::class, $entry);
        $row = $this->domain->getConnection()->fetchAssociative('SELECT actor_id, subject_id FROM ddd_audit_entry WHERE action = ?', ['invoice.sent']);
        $this->assertSame(['actor_id' => self::ALICE, 'subject_id' => self::BOB], $row);
        $this->assertSame(0, (int) $this->domain->getConnection()->fetchOne('SELECT COUNT(*) FROM ddd_invoice'));
        $this->assertTrue($this->domain->getUnitOfWork()->isScheduledForInsert($pending));

        $this->domain->flush();
        $this->assertSame(self::ALICE, $this->domain->getConnection()->fetchOne('SELECT created_by FROM ddd_invoice WHERE id = ?', ['inv-pending']));
        $this->assertSame(1, (int) $this->domain->getConnection()->fetchOne('SELECT COUNT(*) FROM ddd_audit_entry'));
    }
}
