<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogReader;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddTestKernel;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function array_merge;
use function array_unique;
use function count;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Since 2.1: {@see ActionLogReaderInterface} on the DDD path (id-mode log mapped from the shipped XML, no repository
 * class, so the reader builds a plain repository). Real kernel, DoctrineBundle, SQLite.
 */
final class ActionLogReaderDddTest extends TestCase
{
    private DddTestKernel $kernel;

    private EntityManagerInterface $em;

    private Connection $connection;

    private ActionLogReaderInterface $reader;

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

        $reader = $this->kernel->getContainer()->get('test.action_log_reader');
        self::assertInstanceOf(ActionLogReader::class, $reader);
        $this->reader = $reader;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testViewsCarryIdsAndScalars(): void
    {
        $id = $this->insert('invoice.sent', 'alice', 'bob', '2026-01-01 10:00:00', ['invoice' => 'inv-1'], 'tenant-a', '192.0.2.1');
        $this->insert('system.cleanup', null, null, '2026-01-01 09:00:00');

        $page = $this->reader->pageByCriteria(new ActionLogCriteria());
        $this->assertCount(2, $page->items);
        $this->assertNull($page->next);
        $this->assertFalse($page->hasMore());

        $view = $page->items[0];
        $this->assertSame($id, $view->id);
        $this->assertSame('invoice.sent', $view->action);
        $this->assertSame('alice', $view->actorId);
        $this->assertSame('bob', $view->subjectId);
        $this->assertSame(['invoice' => 'inv-1'], $view->context);
        $this->assertSame('tenant-a', $view->scope);
        $this->assertSame('192.0.2.1', $view->ipAddress);
        $this->assertSame('2026-01-01 10:00:00', $view->createdAt->format('Y-m-d H:i:s'));

        $system = $page->items[1];
        $this->assertNull($system->actorId);
        $this->assertNull($system->subjectId);
        $this->assertNull($system->scope);
        $this->assertNull($system->ipAddress);
        $this->assertSame([], $system->context);
    }

    /** Pages walk every row exactly once, newest first, ties on created_at broken by id, through a token round trip. */
    public function testCursorPagesWalkEveryRowOnce(): void
    {
        $expected = [];
        for ($i = 0; $i < 4; ++$i) {
            $expected[] = $this->insert('login', 'alice', null, '2026-01-01 10:00:00');
        }
        $expected[] = $this->insert('login', 'alice', null, '2026-01-01 09:00:00');
        $expected[] = $this->insert('login', 'alice', null, '2026-01-01 11:00:00');
        $expected[] = $this->insert('login', 'alice', null, '2026-01-01 09:00:00');
        $expected   = [$expected[5], $expected[3], $expected[2], $expected[1], $expected[0], $expected[6], $expected[4]];

        $seen  = [];
        $after = null;
        $pages = 0;
        do {
            $page  = $this->reader->pageByCriteria(new ActionLogCriteria(actor: 'alice', limit: 3), $after);
            $seen  = array_merge($seen, $this->ids($page->items));
            $after = $page->next instanceof ActionLogCursor ? ActionLogCursor::fromToken($page->next->toToken()) : null;
            ++$pages;
        } while ($after instanceof ActionLogCursor);

        $this->assertSame($expected, $seen);
        $this->assertSame(3, $pages);
        $this->assertSame(7, $this->reader->countByCriteria(new ActionLogCriteria(actor: 'alice')));
    }

    /** Exactly `limit` rows left: the page is full and says there is nothing more (limit + 1 read). */
    public function testAFullLastPageHasNoNextCursor(): void
    {
        $this->insert('login', 'alice', null, '2026-01-01 10:00:00');
        $this->insert('login', 'alice', null, '2026-01-01 10:00:01');

        $page = $this->reader->pageByCriteria(new ActionLogCriteria(limit: 2));
        $this->assertCount(2, $page->items);
        $this->assertNull($page->next);
    }

    /**
     * REQ-RUNTIME-003 (reads under concurrent writes): rows recorded between two pages neither repeat nor shift the rows
     * already listed; an offset would list one of them twice.
     */
    public function testRowsRecordedBetweenPagesNeitherRepeatNorSkip(): void
    {
        $old = [];
        for ($i = 0; $i < 5; ++$i) {
            $old[] = $this->insert('login', 'alice', null, sprintf('2026-01-01 10:00:%02d', $i));
        }

        $first = $this->reader->pageByCriteria(new ActionLogCriteria(limit: 2));
        $this->insert('login', 'alice', null, '2026-01-01 12:00:00');
        $this->insert('login', 'alice', null, '2026-01-01 12:00:01');
        $this->assertNotNull($first->next);
        $second = $this->reader->pageByCriteria(new ActionLogCriteria(limit: 2), $first->next);
        $this->assertNotNull($second->next);
        $third = $this->reader->pageByCriteria(new ActionLogCriteria(limit: 2), $second->next);

        $seen = array_merge($this->ids($first->items), $this->ids($second->items), $this->ids($third->items));
        $this->assertSame([$old[4], $old[3], $old[2], $old[1], $old[0]], $seen);
        $this->assertCount(count($seen), array_unique($seen));
        $this->assertNull($third->next);
    }

    public function testFiltersLatestAndInvolving(): void
    {
        $sent   = $this->insert(SampleAction::Updated->value, 'alice', 'bob', '2026-01-01 10:00:00', scope: 'tenant-a');
        $viewed = $this->insert('invoice.viewed', 'bob', null, '2026-01-02 10:00:00', scope: 'tenant-b');
        $other  = $this->insert('invoice.viewed', 'carol', 'alice', '2026-01-03 10:00:00', scope: 'tenant-a');

        $this->assertSame([$sent], $this->ids($this->reader->pageByCriteria(new ActionLogCriteria(actions: SampleAction::Updated))->items));
        $this->assertSame([$other, $sent], $this->ids($this->reader->pageByCriteria(new ActionLogCriteria(scope: 'tenant-a'))->items));
        $this->assertSame([$viewed], $this->ids($this->reader->pageByCriteria(new ActionLogCriteria(from: new DateTimeImmutable('2026-01-02 00:00:00'), to: new DateTimeImmutable('2026-01-02 23:59:59')))->items));
        $this->assertSame([$viewed, $sent], $this->ids($this->reader->pageInvolving('bob')->items));
        $this->assertSame([$viewed], $this->ids($this->reader->pageInvolving('bob', ['invoice.viewed'])->items));
        $this->assertSame([$viewed], $this->ids($this->reader->pageInvolving('bob', from: new DateTimeImmutable('2026-01-02 00:00:00'))->items));
        $this->assertSame([$sent], $this->ids($this->reader->pageInvolving('bob', to: new DateTimeImmutable('2026-01-01 23:00:00'))->items));
        $this->assertSame([$other], $this->ids($this->reader->latestEntries(1)));
        $this->assertSame([$other, $viewed, $sent], $this->ids($this->reader->latestEntries()));
        $this->assertSame([$other], $this->ids($this->reader->pageByCriteria(new ActionLogCriteria(limit: 1, offset: 2))->items), 'offset is ignored');
        $this->assertSame([$other], $this->ids($this->reader->pageByCriteria(new ActionLogCriteria(limit: 0))->items), 'the page holds one row at least');

        $this->assertSame([], $this->reader->pageByCriteria(new ActionLogCriteria(actions: []))->items);
        $this->assertSame([], $this->reader->pageByCriteria(new ActionLogCriteria(scope: []))->items);
        $this->assertSame([], $this->reader->pageInvolving('bob', [])->items);
        $this->assertSame([], $this->reader->pageInvolving('')->items, 'an unusable id matches nothing');
        $this->assertSame(0, $this->reader->countByCriteria(new ActionLogCriteria(actions: [])));
        $this->assertSame(2, $this->reader->countByCriteria(new ActionLogCriteria(involving: 'bob')));
    }

    public function testThePageSizeIsCapped(): void
    {
        $this->connection->beginTransaction();
        for ($i = 0; $i <= ActionLogReaderInterface::MAX_LIMIT; ++$i) {
            $this->insert('login', null, null, '2026-01-01 10:00:00');
        }
        $this->connection->commit();

        $page = $this->reader->pageByCriteria(new ActionLogCriteria(limit: 5000));
        $this->assertCount(ActionLogReaderInterface::MAX_LIMIT, $page->items);
        $this->assertNotNull($page->next);
        $this->assertCount(1, $this->reader->pageByCriteria(new ActionLogCriteria(limit: 5000), $page->next)->items);
    }

    /**
     * FrankenPHP worker, kernel kept alive: id-mode reads hydrate arrays, so nothing enters (or stays in) the entity
     * manager however many requests read the log, and nothing is flushed.
     */
    public function testReadsLeaveTheEntityManagerUntouched(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->insert('login', 'alice', 'bob', sprintf('2026-01-01 10:00:%02d', $i));
        }

        $uow = $this->em->getUnitOfWork();
        for ($request = 0; $request < 300; ++$request) {
            $page = $this->reader->pageInvolving($request % 2 === 0 ? 'alice' : 'bob', limit: 5);
            $this->assertCount(5, $page->items);
            $this->reader->latestEntries(5);
            $this->reader->countByCriteria(new ActionLogCriteria(actor: 'alice'));
        }

        $this->assertSame(0, $uow->size());
        $this->assertSame([], $uow->getIdentityMap());
        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function insert(string $action, ?string $actor, ?string $subject, string $at, array $context = [], ?string $scope = null, ?string $ip = null): int
    {
        $this->connection->insert('ddd_audit_entry', [
            'action'     => $action,
            'actor_id'   => $actor,
            'subject_id' => $subject,
            'context'    => json_encode($context === [] ? new stdClass() : $context),
            'scope'      => $scope,
            'ip_address' => $ip,
            'created_at' => $at,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param list<ActionLogView> $views
     *
     * @return list<int>
     */
    private function ids(array $views): array
    {
        return array_map(static fn (ActionLogView $view): int => $view->id, $views);
    }
}
