<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostCascadeActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostIdActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostKeyedTenant;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostObjectActionLog;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostParty;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostPerson;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostRelationsTestKernel;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostTenant;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\HostTenantProfile;
use Nowo\AuditKitBundle\Tests\Fixtures\HostRelations\TenantStampingListener;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sprintf;

/**
 * Since 2.0, the isolated default and host entities (lesson of the wave-1 majors: a host-managed entity reaching a row
 * flushed on the bundle's own manager fails with "A new entity was found through the relationship"). The action log
 * rows reference host entities through their own to-one associations, set by a host Doctrine listener on the shared
 * event manager, and through the actor / subject relations of a relation-mode log.
 *
 * - The isolated write stores the foreign keys and never touches the host's unit of work: the host's instances stay
 *   managed by the host (nothing detached), and their pending changes stay pending.
 * - Targets whose identifier is an association, and the root of a single-table hierarchy (looked up), are referenced.
 * - A host entity that is not stored yet, a row deleted after the host loaded it, and an association that cascades
 *   persist into the host's entity (which the bundle manager would insert a second time) are refused with a clear
 *   message before anything is flushed; nothing is written and the host's instance keeps its identifier.
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

    private HostTenantProfile $profile;

    private HostParty $party;

    private HostKeyedTenant $keyed;

    private ?string $url = null;

    /** The Doctrine URL of the database to run on, or null for in-memory SQLite (may skip the test). */
    abstract protected function databaseUrl(): ?string;

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            // A real database is shared with the other Database tests: leave no table behind.
            if ($this->url !== null && isset($this->em)) {
                $this->em->clear();
                (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
            }
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
        $this->assertSame($this->tenant->id, (int) $row['profile_tenant_id']);
        $this->assertSame($this->party->id, (int) $row['party_id']);
        $this->assertSame('board.unlinked', $entry->getAction());

        // The host's instances are still the host's: managed, not detached, their change still pending.
        foreach ([$this->tenant, $this->reviewer, $this->alice, $this->bob, $this->profile, $this->party] as $held) {
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
    public function testAHostEntityWhoseRowIsGoneIsRefusedAndNothingIsWritten(string $logClass): void
    {
        $this->boot($logClass, null);
        $this->em->getConnection()->delete('hr_party', ['id' => $this->party->id]);

        try {
            $this->record($logClass);
            $this->fail('A reference to a deleted row must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('the action log association "party" holds an entity of your entity manager whose row no longer exists', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->table($logClass)));
        $this->assertTrue($this->em->contains($this->party));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cascadedTargets(): iterable
    {
        yield 'identity id (assigned at insert)' => ['tenant'];
        yield 'pre-insert generator (assigned during persist)' => ['keyed'];
    }

    #[DataProvider('cascadedTargets')]
    public function testACascadePersistIntoAHostEntityIsRefusedWithoutASecondRowOrANewId(string $association): void
    {
        $this->boot(HostCascadeActionLog::class, null);
        $this->listener->current = $association === 'tenant' ? $this->tenant : null;
        $this->listener->keyed   = $association === 'keyed' ? $this->keyed : null;
        $tenantId                = $this->tenant->id;
        $keyedId                 = $this->keyed->id;

        try {
            $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id);
            $this->fail('A cascade persist into a host entity must be refused.');
        } catch (LogicException $e) {
            $this->assertStringContainsString(sprintf('the action log association "%s" cascades persist into an entity of your entity manager', $association), $e->getMessage());
        }

        // No second row, the host's instances keep their identifiers and stay the host's.
        $connection = $this->em->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_cascade_action_log'));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_tenant'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_keyed_tenant'));
        $this->assertSame($tenantId, $this->tenant->id);
        $this->assertSame($keyedId, $this->keyed->id);
        $this->assertTrue($this->em->contains($this->tenant));
        $this->assertTrue($this->em->contains($this->keyed));

        // The host's next flush still updates its own rows.
        $this->tenant->name = 'Renamed';
        $this->keyed->name  = 'Renamed';
        $this->em->flush();
        $this->assertSame(['Renamed'], $this->tenantNames());
        $this->assertSame('Renamed', $connection->fetchOne('SELECT name FROM hr_keyed_tenant WHERE id = ?', [$keyedId]));
    }

    public function testACascadePersistIntoAHostEntityIsHarmlessInHostMode(): void
    {
        $this->boot(HostCascadeActionLog::class, ActionLogRecorder::FLUSH_HOST);
        $this->listener->keyed = $this->keyed;

        $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id);

        $connection = $this->em->getConnection();
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_cascade_action_log WHERE tenant_id = ? AND keyed_tenant_id = ?', [$this->tenant->id, $this->keyed->id]));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_tenant'));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM hr_keyed_tenant'));
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
        return $logClass !== HostObjectActionLog::class
            ? $this->recorder->recordIdsAndFlush('board.unlinked', $this->alice->id, $this->bob->id)
            : $this->recorder->recordAndFlush('board.unlinked', $this->alice, $this->bob);
    }

    /**
     * @param class-string<HostCascadeActionLog|HostIdActionLog|HostObjectActionLog> $logClass
     */
    private function boot(string $logClass, ?string $flushMode): void
    {
        $url       = $this->databaseUrl();
        $this->url = $url;
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
        $this->party    = new HostPerson('Ada');
        $this->keyed    = new HostKeyedTenant('Keyed');
        foreach ([$this->alice, $this->bob, $this->tenant, $this->reviewer, $this->party, $this->keyed] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->profile = new HostTenantProfile($this->tenant);
        $em->persist($this->profile);
        $em->flush();

        $this->listener->current  = $this->tenant;
        $this->listener->reviewer = $this->reviewer;
        $this->listener->profile  = $this->profile;
        $this->listener->party    = $this->party;
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
        return $logClass === HostObjectActionLog::class ? 'hr_object_action_log' : 'hr_id_action_log';
    }

    /** @return list<string> the stored name of the main tenant */
    private function tenantNames(): array
    {
        /** @var list<string> $names */
        $names = $this->em->getConnection()->fetchFirstColumn('SELECT name FROM hr_tenant WHERE id = ?', [$this->tenant->id]);

        return $names;
    }
}
