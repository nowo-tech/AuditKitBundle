<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostIdActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostObjectActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostRelationsTestKernel;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostTenant;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\TenantStampingListener;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Since 2.0, the isolated default and host entities (lesson of the wave-1 majors: a host-managed entity reaching a row
 * flushed on the bundle's own manager fails with "A new entity was found through the relationship"). The action log
 * rows reference host entities through their own to-one associations, set by a host Doctrine listener on the shared
 * event manager, and through the actor / subject relations of a relation-mode log.
 *
 * - The isolated write stores the foreign keys and never touches the host's unit of work: the host's instances stay
 *   managed by the host (nothing detached), and their pending changes stay pending.
 * - A host entity that is not stored yet is refused with a clear message, and nothing is written.
 * - An explicit `flush_mode: host` writes the row with the host's changes, as in 1.x.
 *
 * Run on in-memory SQLite (Integration) and on a real database (Database).
 */
abstract class HostRelationsTestCase extends TestCase
{
    private HostRelationsTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private TenantStampingListener $listener;

    private KernelUser $alice;

    private KernelUser $bob;

    private HostTenant $tenant;

    private HostTenant $reviewer;

    /** The Doctrine URL of the database to run on, or null for in-memory SQLite (may skip the test). */
    abstract protected function databaseUrl(): ?string;

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /**
     * @return iterable<string, array{class-string<HostIdActionLog|HostObjectActionLog>}>
     */
    public static function logClasses(): iterable
    {
        yield 'id-mode log (AbstractIdActionLog)' => [HostIdActionLog::class];
        yield 'relation-mode log (AbstractActionLog)' => [HostObjectActionLog::class];
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    #[DataProvider('logClasses')]
    public function testTheIsolatedDefaultStoresHostRelationsAndLeavesTheHostUnitOfWorkAlone(string $logClass): void
    {
        $this->boot($logClass, null);
        $this->tenant->name = 'Renamed, not flushed';

        $entry = $this->record($logClass);

        $row = $this->row($logClass);
        $this->assertSame($this->tenant->id, (int) $row['tenant_id']);
        $this->assertSame($this->reviewer->id, (int) $row['reviewer_tenant_id']);
        $this->assertSame($this->alice->id, (int) $row['actor_id']);
        $this->assertSame($this->bob->id, (int) $row['subject_id']);
        $this->assertSame('board.unlinked', $entry->getAction());

        // The host's instances are still the host's: managed, not detached, their change still pending.
        foreach ([$this->tenant, $this->reviewer, $this->alice, $this->bob] as $held) {
            $this->assertTrue($this->em->contains($held));
        }
        $this->assertFalse($this->em->contains($entry));
        $this->assertSame(['Main'], $this->tenantNames());
        $this->em->flush();
        $this->assertSame(['Renamed, not flushed'], $this->tenantNames());
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    #[DataProvider('logClasses')]
    public function testRepeatedIsolatedWritesKeepReferencingTheSameHostEntities(string $logClass): void
    {
        $this->boot($logClass, null);
        // The reviewer is a one-to-one (unique foreign key): only the many-to-one tenant repeats.
        $this->listener->reviewer = null;

        for ($i = 0; $i < 20; ++$i) {
            $this->record($logClass);
        }

        $this->assertSame(20, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($logClass) . ' WHERE tenant_id = ?', [$this->tenant->id]));
        $this->assertTrue($this->em->contains($this->tenant));
        $this->assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM hr_tenant'));
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    #[DataProvider('logClasses')]
    public function testAHostEntityThatIsNotStoredYetIsRefusedAndNothingIsWritten(string $logClass): void
    {
        $this->boot($logClass, null);
        $unsaved = new HostTenant('Pending');
        $this->em->persist($unsaved);
        $this->listener->current = $unsaved;

        try {
            $this->record($logClass);
            $this->fail('An unsaved host entity must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('the action log association "tenant" holds an entity of your entity manager that is not stored yet', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($logClass)));
        $this->assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($unsaved));
        $this->assertTrue($this->em->isOpen());
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    #[DataProvider('logClasses')]
    public function testAnExplicitHostModeWritesTheRowWithTheHostChangesAsIn1x(string $logClass): void
    {
        $this->boot($logClass, ActionLogRecorder::FLUSH_HOST);
        $this->tenant->name = 'Renamed';

        $entry = $this->record($logClass);

        $this->assertSame($this->tenant->id, (int) $this->row($logClass)['tenant_id']);
        $this->assertSame(['Renamed'], $this->tenantNames());
        $this->assertTrue($this->em->contains($entry));
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    private function record(string $logClass): ActionLogEntryInterface
    {
        return $logClass === HostIdActionLog::class
            ? $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id, $this->bob->id)
            : $this->recorder->recordAndFlush('board.unlinked', $this->alice, $this->bob);
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    private function boot(string $logClass, ?string $flushMode): void
    {
        $url = $this->databaseUrl();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-host-relations');
        $this->kernel = new HostRelationsTestKernel($logClass, $flushMode, $url);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($url !== null) {
            $schema->dropSchema($metadata);
        }
        $schema->createSchema($metadata);

        $recorder = $container->get('test.action_log_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);
        $this->recorder = $recorder;
        $listener       = $container->get(TenantStampingListener::class);
        self::assertInstanceOf(TenantStampingListener::class, $listener);
        $this->listener = $listener;

        $this->alice    = new KernelUser('alice@example.com');
        $this->bob      = new KernelUser('bob@example.com');
        $this->tenant   = new HostTenant('Main');
        $this->reviewer = new HostTenant('Reviewer');
        foreach ([$this->alice, $this->bob, $this->tenant, $this->reviewer] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $this->listener->current  = $this->tenant;
        $this->listener->reviewer = $this->reviewer;
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     *
     * @return array<string, mixed>
     */
    private function row(string $logClass): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM ' . $this->table($logClass));
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param class-string<HostIdActionLog|HostObjectActionLog> $logClass
     */
    private function table(string $logClass): string
    {
        return $logClass === HostIdActionLog::class ? 'hr_id_action_log' : 'hr_object_action_log';
    }

    /** @return list<string> the stored name of the main tenant */
    private function tenantNames(): array
    {
        /** @var list<string> $names */
        $names = $this->em->getConnection()->fetchFirstColumn('SELECT name FROM hr_tenant WHERE id = ?', [$this->tenant->id]);

        return $names;
    }
}
