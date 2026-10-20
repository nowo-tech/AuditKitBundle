<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\Command\PurgeActionLogCommand;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\CountingClock;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Repository\KernelActionLogRepository;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function sprintf;

/** Real kernel + DoctrineBundle + SQLite: action log mapping, recorder, filters, keyset purge and command. */
final class ActionLogIntegrationTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private KernelActionLogRepository $repository;

    private KernelUser $alice;

    private KernelUser $bob;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel();
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $repository = $this->em->getRepository(KernelActionLog::class);
        self::assertInstanceOf(KernelActionLogRepository::class, $repository);
        $this->repository = $repository;

        $this->alice = new KernelUser('alice@example.com');
        $this->bob   = new KernelUser('bob@example.com');
        $this->em->persist($this->alice);
        $this->em->persist($this->bob);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testMappingUsesExplicitColumnsAndResolvedUserTarget(): void
    {
        $this->assertTrue($this->em->getClassMetadata(AbstractActionLog::class)->isMappedSuperclass);

        $metadata = $this->em->getClassMetadata(KernelActionLog::class);
        $this->assertSame('kernel_action_log', $metadata->getTableName());

        $expected = [
            'action'    => ['action', 'string', 48, false],
            'context'   => ['context', 'json', null, false],
            'scope'     => ['scope', 'string', 64, true],
            'ipAddress' => ['ip_address', 'string', 45, true],
            'createdAt' => ['created_at', 'datetime_immutable', null, false],
        ];
        foreach ($expected as $field => [$column, $type, $length, $nullable]) {
            $mapping = $metadata->getFieldMapping($field);
            $this->assertSame($column, $mapping['columnName'], $field);
            $this->assertSame($type, $mapping['type'], $field);
            $this->assertSame($length, $mapping['length'] ?? null, $field);
            $this->assertSame($nullable, $mapping['nullable'] ?? false, $field);
        }

        foreach (['actor' => 'actor_id', 'subject' => 'subject_id'] as $field => $column) {
            $association = $metadata->getAssociationMapping($field);
            $this->assertSame(KernelUser::class, $association['targetEntity']);
            $joinColumn = $association['joinColumns'][0];
            $this->assertSame($column, $joinColumn['name']);
            $this->assertSame('SET NULL', $joinColumn['onDelete']);
            $this->assertTrue($joinColumn['nullable'] ?? true);
        }

        $table = (new SchemaTool($this->em))->getSchemaFromMetadata([$metadata])->getTable('kernel_action_log');
        $this->assertTrue($table->hasIndex('idx_kernel_action_log_scope_created'));
        $this->assertCount(2, $table->getForeignKeys());
    }

    public function testRecorderPersistsEnumActionIpAndScopeFromContext(): void
    {
        $requestStack = $this->kernel->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.7']));

        $recorder = $this->recorder();
        $this->assertSame(KernelActionLog::class, $recorder->getEntityClass());

        $entry    = $recorder->recordAndFlush(SampleAction::Viewed, $this->alice, $this->bob, ['project_uuid' => 'p-1', 'name' => 'Demo']);
        $tooLong  = $recorder->record('item.updated', $this->alice, context: ['project_uuid' => str_repeat('x', 65)]);
        $explicit = $recorder->record('item.updated', null, null, ['project_uuid' => 'ignored'], 'explicit');
        $noKey    = $recorder->record('item.updated', null, null, ['project_uuid' => 42]);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->repository->find($entry->getId());
        self::assertInstanceOf(KernelActionLog::class, $reloaded);
        $this->assertSame('item.viewed', $reloaded->getAction());
        $this->assertTrue($reloaded->isAction(SampleAction::Viewed));
        $this->assertFalse($reloaded->isAction('item.updated'));
        $this->assertSame('203.0.113.7', $reloaded->getIpAddress());
        $this->assertSame('p-1', $reloaded->getScope());
        $this->assertSame(['project_uuid' => 'p-1', 'name' => 'Demo'], $reloaded->getContext());
        $actor = $reloaded->getActor();
        self::assertInstanceOf(KernelUser::class, $actor);
        $this->assertSame($this->alice->id, $actor->id);
        $subject = $reloaded->getSubject();
        self::assertInstanceOf(KernelUser::class, $subject);
        $this->assertSame($this->bob->id, $subject->id);

        $this->assertNull($tooLong->getScope());
        $this->assertSame('explicit', $explicit->getScope());
        $this->assertNull($noKey->getScope());
        $this->assertNull($noKey->getActor());

        $requestStack->pop();
        $this->assertNull($recorder->record('item.updated')->getIpAddress());
    }

    public function testRecorderWithoutScopeKeyOrIpCapture(): void
    {
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.8']));

        $entry = (new ActionLogRecorder($registry, $requestStack, KernelActionLog::class, null, false))
            ->record('item.updated', context: ['project_uuid' => 'p-1']);

        $this->assertNull($entry->getScope());
        $this->assertNull($entry->getIpAddress());
    }

    public function testFilters(): void
    {
        $recorder = $this->recorder();
        $recorder->record(SampleAction::Viewed, $this->alice, null, ['project_uuid' => 'p-1']);
        $recorder->record(SampleAction::Updated, $this->alice, $this->bob, ['project_uuid' => 'p-2']);
        $recorder->record(SampleAction::Viewed, $this->bob, null, ['project_uuid' => 'p-1']);
        $old = $recorder->record(SampleAction::Deleted, null, $this->alice);
        self::assertInstanceOf(KernelActionLog::class, $old);
        $old->backdate('-10 days');
        $this->em->flush();
        $this->em->clear();

        $this->assertSame(['item.updated', 'item.viewed'], $this->actions(new ActionLogCriteria(actor: $this->alice)));
        $this->assertSame(['item.deleted'], $this->actions(new ActionLogCriteria(subject: $this->alice)));
        $this->assertSame(['item.updated', 'item.viewed', 'item.deleted'], $this->actions(new ActionLogCriteria(involving: $this->alice)));
        $this->assertSame(['item.viewed', 'item.updated'], $this->actions(new ActionLogCriteria(involving: $this->bob->id)));
        $this->assertSame(['item.viewed', 'item.viewed'], $this->actions(new ActionLogCriteria(scope: 'p-1')));
        $this->assertSame(['item.viewed', 'item.updated', 'item.viewed'], $this->actions(new ActionLogCriteria(scope: ['p-1', 'p-2'])));
        $this->assertSame(['item.updated'], $this->actions(new ActionLogCriteria(actions: SampleAction::Updated)));
        $this->assertSame(['item.viewed', 'item.viewed', 'item.deleted'], $this->actions(new ActionLogCriteria(actions: [SampleAction::Viewed, 'item.deleted'])));
        $this->assertSame(['item.deleted'], $this->actions(new ActionLogCriteria(to: new DateTimeImmutable('-1 day'))));
        $this->assertSame(['item.viewed', 'item.updated', 'item.viewed'], $this->actions(new ActionLogCriteria(from: new DateTimeImmutable('-1 day'))));
        $this->assertSame([], $this->actions(new ActionLogCriteria(actions: [])));
        $this->assertSame([], $this->actions(new ActionLogCriteria(scope: [])));
        $this->assertSame(0, $this->repository->countByCriteria(new ActionLogCriteria(scope: [])));
        $this->assertSame(2, $this->repository->countByCriteria(new ActionLogCriteria(actor: $this->alice, limit: 1)));

        $latest = $this->repository->findLatest(2);
        $this->assertCount(2, $latest);
        $this->assertGreaterThan($latest[1]->getId(), $latest[0]->getId());
        $this->assertSame(['item.updated'], $this->actions(new ActionLogCriteria(limit: 1, offset: 1)));

        $involving = $this->repository->findInvolving($this->alice, [SampleAction::Deleted], new DateTimeImmutable('-30 days'), new DateTimeImmutable('+1 day'));
        $this->assertSame(['item.deleted'], array_map(static fn (AbstractActionLog $row): string => $row->getAction(), $involving));
    }

    public function testKeysetPurgeDeletesOnlyOldRowsOfListedActions(): void
    {
        $this->seedPurgeRows();

        $this->assertSame(0, $this->repository->purgeBefore(new DateTimeImmutable('-30 days'), []));
        $this->assertSame(5, $this->repository->purgeBefore(new DateTimeImmutable('-30 days'), [SampleAction::Viewed], 2));
        $this->assertSame(['item.viewed', 'item.updated', 'item.updated'], $this->actions(new ActionLogCriteria()));

        $this->assertSame(1, $this->repository->purgeBefore(new DateTimeImmutable('-30 days'), null, 1));
        $this->assertSame(['item.viewed', 'item.updated'], $this->actions(new ActionLogCriteria()));
    }

    public function testPurgeCommandUsesConfiguredActionsByDefault(): void
    {
        $this->seedPurgeRows();
        // The command takes its cutoff from the kernel clock (fixed in the test kernel); the rows are seeded
        // relative to the wall clock, so align the kernel clock with it first.
        $clock = $this->kernel->getContainer()->get('clock');
        self::assertInstanceOf(CountingClock::class, $clock);
        $clock->modify(sprintf('%+d seconds', time() - $clock->now()->getTimestamp()));
        $application = new Application($this->kernel);

        $tester = new CommandTester($application->find(PurgeActionLogCommand::NAME));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('5 row(s) older than 30 days purged (item.viewed)', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--action' => ['item.updated'], '--older-than-days' => '1']));
        $this->assertStringContainsString('2 row(s) older than 1 days purged (item.updated)', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--all-actions' => true, '--batch-size' => '10']));
        $this->assertStringContainsString('0 row(s) older than 30 days purged (all actions)', $tester->getDisplay());

        $this->assertSame(Command::INVALID, $tester->execute(['--older-than-days' => '0']));
        $this->assertSame(Command::INVALID, $tester->execute(['--batch-size' => '0']));
    }

    public function testPurgeCommandRefusesWithoutActionsAndFallsBackToBaseRepository(): void
    {
        $this->seedPurgeRows();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $tester = new CommandTester(new PurgeActionLogCommand($registry, KernelActionLog::class));
        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('No action to purge', $tester->getDisplay());

        $plainRegistry = $this->createMock(ManagerRegistry::class);
        $plainRegistry->method('getRepository')->willReturn($this->createStub(ObjectRepository::class));
        $plainRegistry->method('getManagerForClass')->willReturn($this->em);

        $tester = new CommandTester(new PurgeActionLogCommand($plainRegistry, KernelActionLog::class, ['item.viewed']));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('5 row(s) older than 90 days purged', $tester->getDisplay());
    }

    public function testPurgeCommandUsesTheInjectedClockForTheCutoff(): void
    {
        $this->seedPurgeRows();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        // A clock 200 days ahead makes every seeded row older than 30 days.
        $clock  = new MockClock(new DateTimeImmutable('+200 days'));
        $tester = new CommandTester(new PurgeActionLogCommand($registry, KernelActionLog::class, [], 90, 100, $clock));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--all-actions' => true, '--older-than-days' => '30']));
        $this->assertStringContainsString('8 row(s) older than 30 days purged (all actions)', $tester->getDisplay());
    }

    /** Five old views (purgeable), one recent view, one old and one recent update. */
    private function seedPurgeRows(): void
    {
        $recorder = $this->recorder();
        for ($i = 0; $i < 5; ++$i) {
            $this->backdated($recorder->record(SampleAction::Viewed, $this->alice), '-100 days');
        }
        $recorder->record(SampleAction::Viewed, $this->alice);
        $this->backdated($recorder->record(SampleAction::Updated, $this->alice), '-100 days');
        $this->backdated($recorder->record(SampleAction::Updated, $this->alice), '-2 days');
        $this->em->flush();
        $this->em->clear();
    }

    private function backdated(AbstractActionLog $entry, string $modifier): void
    {
        self::assertInstanceOf(KernelActionLog::class, $entry);
        $entry->backdate($modifier);
    }

    /**
     * @return list<string>
     */
    private function actions(ActionLogCriteria $criteria): array
    {
        return array_map(static fn (AbstractActionLog $row): string => $row->getAction(), $this->repository->findByCriteria($criteria));
    }

    private function recorder(): ActionLogRecorder
    {
        $recorder = $this->kernel->getContainer()->get('test.action_log_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);

        return $recorder;
    }
}
