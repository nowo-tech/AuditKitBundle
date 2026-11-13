<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Support\LookalikeCollation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function count;
use function intdiv;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function sys_get_temp_dir;

/**
 * REQ-SEC-006 (look-alike ids) for an id-mode action log. The `actor_id` / `subject_id` columns get an SQLite
 * collation that folds like the default MySQL / MariaDB ones ({@see LookalikeCollation}), so SQL alone returns the
 * look-alike rows of `bob`; the repository must return, page and count only the rows that hold `bob` byte for byte.
 */
final class ActionLogLookalikeIdTest extends TestCase
{
    private const LOOKALIKES = ['Bob', "b\u{00F3}b", 'bob ', "bob\n", "bob\u{00A0}", "bo\u{200B}b"];

    private DddTestKernel $kernel;

    private EntityManagerInterface $em;

    private Connection $connection;

    private int $clock = 0;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel');
        $this->kernel = new DddTestKernel();
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em         = $em;
        $this->connection = $em->getConnection();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        // Same table, folding collation on the id columns (what a MySQL / MariaDB table gets without the listener).
        LookalikeCollation::register($this->connection);
        $ddl = $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'ddd_audit_entry'");
        self::assertIsString($ddl);
        $ddl = preg_replace('/\b(actor_id|subject_id) VARCHAR\(64\)/', '$1 VARCHAR(64) COLLATE ' . LookalikeCollation::NAME, $ddl);
        self::assertIsString($ddl);
        $this->connection->executeStatement('DROP TABLE ddd_audit_entry');
        $this->connection->executeStatement($ddl);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testTheTableFoldsLikeMysql(): void
    {
        $this->seed();

        // Guard: SQL alone matches every look-alike, so the assertions below test the repository, not SQLite.
        self::assertSame(1 + count(self::LOOKALIKES), (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ddd_audit_entry WHERE actor_id = 'bob'"));
    }

    public function testActorFilterReturnsAndCountsTheExactRowsOnly(): void
    {
        $ids = $this->seed();

        self::assertSame([$ids['bob']], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(actor: 'bob'))));
        self::assertSame(1, $this->repository()->countByCriteria(new ActionLogCriteria(actor: 'bob')));
        self::assertSame([$ids['Bob']], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(actor: 'Bob'))));
        self::assertSame(1, $this->repository()->countByCriteria(new ActionLogCriteria(actor: "b\u{00F3}b")));
    }

    public function testThePageIsFilledWithExactRowsBeforeTheLimit(): void
    {
        $ids = $this->seed();

        // The look-alikes are newer: a LIMIT 1 before the exact match would return one of them, or nothing.
        self::assertSame([$ids['bob']], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(actor: 'bob', limit: 1))));
        self::assertSame([$ids['bob']], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(subject: 'bob', limit: 1))));
        self::assertCount(1 + count(self::LOOKALIKES), $this->repository()->findLatest(), 'unfiltered reads are unchanged');
    }

    public function testOffsetCountsExactRowsOnly(): void
    {
        $older = $this->insert('bob', null);
        foreach (self::LOOKALIKES as $lookalike) {
            $this->insert($lookalike, null);
        }
        $newer = $this->insert('bob', null);

        $repository = $this->repository();
        self::assertSame([$newer, $older], $this->ids($repository->findByCriteria(new ActionLogCriteria(actor: 'bob'))));
        self::assertSame([$older], $this->ids($repository->findByCriteria(new ActionLogCriteria(actor: 'bob', limit: 1, offset: 1))));
        self::assertSame([], $this->ids($repository->findByCriteria(new ActionLogCriteria(actor: 'bob', limit: 1, offset: 2))));
    }

    public function testInvolvingMatchesActorOrSubjectExactly(): void
    {
        $asActor   = $this->insert('bob', 'alice');
        $asSubject = $this->insert('alice', 'bob');
        $this->insert('Bob', 'alice');
        $this->insert('alice', 'bob ');
        $this->insert("bob\u{00A0}", "b\u{00F3}b");

        self::assertSame([$asSubject, $asActor], $this->ids($this->repository()->findInvolving('bob')));
        self::assertSame(2, $this->repository()->countByCriteria(new ActionLogCriteria(involving: 'bob')));
        self::assertSame([$asActor], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(actor: 'bob', subject: 'alice'))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableIds(): iterable
    {
        yield 'trailing space' => ['bob '];
        yield 'trailing newline' => ["bob\n"];
        yield 'NBSP' => ["bob\u{00A0}"];
        yield 'ZWSP' => ["bo\u{200B}b"];
    }

    #[DataProvider('unusableIds')]
    public function testAnUnusableFilterIdMatchesNothing(string $id): void
    {
        $this->seed();

        self::assertSame([], $this->repository()->findByCriteria(new ActionLogCriteria(actor: $id)));
        self::assertSame([], $this->repository()->findInvolving($id));
        self::assertSame(0, $this->repository()->countByCriteria(new ActionLogCriteria(subject: $id)));
    }

    public function testTheLoopReadsPastManyLookalikes(): void
    {
        $bob = $this->insert('bob', null);
        $this->connection->beginTransaction();
        for ($i = 0; $i < 1100; ++$i) {
            // One shared timestamp: the keyset also walks ties on created_at by id.
            $this->insert(self::LOOKALIKES[$i % count(self::LOOKALIKES)], null, '2026-01-02 00:00:00');
        }
        $this->connection->commit();

        self::assertSame([$bob], $this->ids($this->repository()->findByCriteria(new ActionLogCriteria(actor: 'bob', limit: 1))));
        self::assertSame(1, $this->repository()->countByCriteria(new ActionLogCriteria(actor: 'bob')));
    }

    public function testTheRecorderStoresUnusableIdsAsNull(): void
    {
        $recorder = $this->kernel->getContainer()->get('test.action_log_id_recorder');
        self::assertInstanceOf(ActionLogIdRecorderInterface::class, $recorder);

        $entry = $recorder->recordIdsAndFlush('login', 'bob ', str_repeat('x', 65));
        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertNull($entry->getActorId());
        self::assertNull($entry->getSubjectId());

        $entry = $recorder->recordIdsAndFlush('login', 'Bob', str_repeat('x', 64));
        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertSame('Bob', $entry->getActorId());
        self::assertSame(str_repeat('x', 64), $entry->getSubjectId());
    }

    /**
     * `bob` (oldest), then one newer row per look-alike, as actor and as subject.
     *
     * @return array<string, int>
     */
    private function seed(): array
    {
        $ids = ['bob' => $this->insert('bob', 'bob')];
        foreach (self::LOOKALIKES as $lookalike) {
            $ids[$lookalike] = $this->insert($lookalike, $lookalike);
        }

        return $ids;
    }

    private function insert(?string $actor, ?string $subject, ?string $at = null): int
    {
        $this->connection->insert('ddd_audit_entry', [
            'action'     => 'login',
            'actor_id'   => $actor,
            'subject_id' => $subject,
            'context'    => '[]',
            'created_at' => $at ?? sprintf('2026-01-01 10:%02d:%02d', intdiv($this->clock, 60) % 60, $this->clock % 60),
        ]);
        ++$this->clock;

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return ActionLogRepository<AuditEntry>
     */
    private function repository(): ActionLogRepository
    {
        $this->em->clear();
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new ActionLogRepository($doctrine, AuditEntry::class);
    }

    /**
     * @param list<AuditEntry> $rows
     *
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (AuditEntry $row): ?int => $row->getId(), $rows);
    }
}
