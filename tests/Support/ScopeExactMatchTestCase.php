<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogEntryInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

use function array_map;
use function count;
use function intdiv;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Audit YC1-M1 (since 2.1): the `scope` filter (tenant / project key) matches byte for byte. The `scope` column keeps
 * the table collation, which on MySQL / MariaDB folds case and accents (and, PAD SPACE, trailing spaces), so SQL alone
 * returns the rows of `Tenant-A`, `tenant-a ` or `ténant-a` for `tenant-a`. The repository and the read port must
 * return, page and count only the rows whose scope is one of the filter scopes exactly, in relation mode
 * ({@see KernelActionLog}) and in id mode ({@see AuditEntry}).
 *
 * Subclasses give the database: in-memory SQLite with a folding collation on `scope` (every job), or a real database
 * (tests/Database, CI job "Databases").
 */
abstract class ScopeExactMatchTestCase extends TestCase
{
    public const MODE_RELATION = 'relation';

    public const MODE_ID = 'id';

    /** Each one is a different tenant from `tenant-a`; a folding collation compares them all equal to it. */
    protected const LOOKALIKES = ['Tenant-A', 'TENANT-A', 'tenant-a ', "t\u{00E9}nant-a"];

    protected Kernel $kernel;

    protected EntityManagerInterface $em;

    protected Connection $connection;

    protected string $table;

    /** @var class-string<ActionLogEntryInterface> */
    private string $entityClass;

    private int $clock = 0;

    /** The database URL, or `null` for in-memory SQLite (may mark the test skipped). */
    abstract protected function databaseUrl(): ?string;

    /** Hook run once the schema exists (the SQLite case swaps in a folding collation on `scope`). */
    protected function afterSchema(): void
    {
    }

    /** Guard: what SQL alone matches for `tenant-a` on this database, given `$folded` rows that fold to it. */
    abstract protected function assertSqlMatches(int $exact, int $folded): void;

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'relation mode' => [self::MODE_RELATION];
        yield 'id mode' => [self::MODE_ID];
    }

    #[DataProvider('modes')]
    public function testScopeFilterReturnsAndCountsTheExactRowsOnly(string $mode): void
    {
        $this->boot($mode);
        $older = $this->insert('tenant-a');
        foreach (self::LOOKALIKES as $lookalike) {
            $this->insert($lookalike);
        }
        $newer = $this->insert('tenant-a');
        $other = $this->insert('tenant-b');
        $this->insert('Tenant-B');

        $this->assertSqlMatches(2, count(self::LOOKALIKES));

        $repository = $this->repository();
        self::assertSame([$newer, $older], $this->ids($repository->findByCriteria(new ActionLogCriteria(scope: 'tenant-a'))));
        self::assertSame(2, $repository->countByCriteria(new ActionLogCriteria(scope: 'tenant-a')));
        self::assertSame([$other, $newer, $older], $this->ids($repository->findByCriteria(new ActionLogCriteria(scope: ['tenant-a', 'tenant-b']))));
        self::assertSame(3, $repository->countByCriteria(new ActionLogCriteria(scope: ['tenant-b', 'tenant-a'])));

        // Each look-alike is its own tenant: it finds its own row only.
        foreach (self::LOOKALIKES as $lookalike) {
            self::assertCount(1, $repository->findByCriteria(new ActionLogCriteria(scope: $lookalike)), $lookalike);
            self::assertSame(1, $repository->countByCriteria(new ActionLogCriteria(scope: $lookalike)), $lookalike);
        }
        self::assertSame([], $repository->findByCriteria(new ActionLogCriteria(scope: 'tenant-c')));
        self::assertCount(4 + count(self::LOOKALIKES), $repository->findLatest(), 'unfiltered reads are unchanged');
    }

    #[DataProvider('modes')]
    public function testThePageIsFilledWithExactRowsBeforeTheLimit(string $mode): void
    {
        $this->boot($mode);
        $older = $this->insert('tenant-a');
        foreach (self::LOOKALIKES as $lookalike) {
            $this->insert($lookalike);
        }
        $newer = $this->insert('tenant-a');
        foreach (self::LOOKALIKES as $lookalike) {
            $this->insert($lookalike);
        }

        $repository = $this->repository();
        // The look-alikes are newer: a LIMIT 1 before the re-check would return one of them.
        self::assertSame([$newer], $this->ids($repository->findByCriteria(new ActionLogCriteria(scope: 'tenant-a', limit: 1))));
        self::assertSame([$older], $this->ids($repository->findByCriteria(new ActionLogCriteria(scope: 'tenant-a', limit: 1, offset: 1))));
        self::assertSame([], $repository->findByCriteria(new ActionLogCriteria(scope: 'tenant-a', limit: 1, offset: 2)));

        $reader = $this->reader();
        $first  = $reader->pageByCriteria(new ActionLogCriteria(scope: 'tenant-a', limit: 1));
        self::assertSame([$newer], $this->viewIds($first->items));
        self::assertInstanceOf(ActionLogCursor::class, $first->next);
        $second = $reader->pageByCriteria(new ActionLogCriteria(scope: 'tenant-a', limit: 1), $first->next);
        self::assertSame([$older], $this->viewIds($second->items));
        self::assertNull($second->next);
        self::assertSame(['tenant-a', 'tenant-a'], array_map(static fn (ActionLogView $view): ?string => $view->scope, [...$first->items, ...$second->items]));
        self::assertSame(2, $reader->countByCriteria(new ActionLogCriteria(scope: 'tenant-a')));
        self::assertSame([], $reader->pageByCriteria(new ActionLogCriteria(scope: 'tenant-c'))->items);
    }

    /** Id mode: the scope and the id re-checks apply together. */
    public function testScopeAndActorFiltersAreBothExact(): void
    {
        $this->boot(self::MODE_ID);
        $match = $this->insert('tenant-a', 'bob');
        $this->insert('Tenant-A', 'bob');
        $this->insert('tenant-a', 'alice');
        $this->insert('tenant-a ', 'bob');

        $repository = $this->repository();
        self::assertSame([$match], $this->ids($repository->findByCriteria(new ActionLogCriteria(actor: 'bob', scope: 'tenant-a'))));
        self::assertSame(1, $repository->countByCriteria(new ActionLogCriteria(actor: 'bob', scope: 'tenant-a')));
        self::assertSame([$match], $this->viewIds($this->reader()->pageByCriteria(new ActionLogCriteria(involving: 'bob', scope: ['tenant-a']))->items));
        self::assertSame(0, $repository->countByCriteria(new ActionLogCriteria(actor: 'bob ', scope: 'tenant-a')), 'an unusable id still matches nothing');
    }

    protected function boot(string $mode): void
    {
        $url = $this->databaseUrl();
        if ($mode === self::MODE_RELATION) {
            (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');
            $this->kernel      = new AuditKitTestKernel(ActionLogRecorder::FLUSH_HOST, $url);
            $this->table       = 'kernel_action_log';
            $this->entityClass = KernelActionLog::class;
        } else {
            (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel');
            $this->kernel      = new DddTestKernel($url);
            $this->table       = 'ddd_audit_entry';
            $this->entityClass = AuditEntry::class;
        }
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em         = $em;
        $this->connection = $em->getConnection();
        $schema           = new SchemaTool($em);
        $metadata         = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $this->afterSchema();
    }

    /** One row of the scope (and, in id mode, of the actor), each newer than the previous one. */
    protected function insert(string $scope, ?string $actorId = null): int
    {
        $row = [
            'action'     => 'item.viewed',
            'context'    => '[]',
            'scope'      => $scope,
            'created_at' => sprintf('2026-01-01 10:%02d:%02d', intdiv($this->clock, 60) % 60, $this->clock % 60),
        ];
        ++$this->clock;
        if ($actorId !== null) {
            $row['actor_id'] = $actorId;
        }

        // With a sequence id generator (PostgreSQL on DBAL 3) the id comes from the sequence.
        $metadata = $this->em->getClassMetadata($this->entityClass);
        if ($metadata->isIdGeneratorSequence()) {
            $platform = $this->connection->getDatabasePlatform();
            $row['id'] = (int) $this->connection->fetchOne($platform->getSequenceNextValSQL($metadata->getSequenceName($platform)));
            $this->connection->insert($this->table, $row);

            return $row['id'];
        }

        $this->connection->insert($this->table, $row);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return ActionLogRepository<ActionLogEntryInterface>
     */
    private function repository(): ActionLogRepository
    {
        $this->em->clear();
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new ActionLogRepository($doctrine, $this->entityClass);
    }

    private function reader(): ActionLogReaderInterface
    {
        $reader = $this->kernel->getContainer()->get('test.action_log_reader');
        self::assertInstanceOf(ActionLogReaderInterface::class, $reader);

        return $reader;
    }

    /**
     * @param list<ActionLogView> $views
     *
     * @return list<int>
     */
    private function viewIds(array $views): array
    {
        return array_map(static fn (ActionLogView $view): int => $view->id, $views);
    }

    /**
     * @param list<ActionLogEntryInterface> $rows
     *
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (ActionLogEntryInterface $row): ?int => $row->getId(), $rows);
    }
}
