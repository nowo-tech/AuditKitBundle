<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * REQ-ARCH-001 rule 6 evidence for `recordAndFlush()` / `recordIdsAndFlush()` (since 1.15).
 *
 * - `flush_mode: host` (default, unchanged): the host entity manager is flushed, pending host changes included.
 * - `flush_mode: isolated`: only the action log row is written; the host's pending inserts and updates are neither
 *   flushed nor cleared, and the host identity map is untouched.
 */
final class ActionLogFlushIsolationTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private KernelUser $alice;

    private KernelUser $bob;

    private KernelArticle $stored;

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testHostModeFlushesPendingHostChangesAsBefore(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        [$pending] = $this->pendingHostChanges();

        $this->recorder->recordAndFlush('item.viewed', $this->alice, $this->bob);

        // The behaviour of earlier versions, kept as the default: the host's changes were flushed too.
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
        $this->assertFalse($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        $this->assertSame(1, $this->actionLogCount());
    }

    public function testIsolatedModeLeavesPendingHostChangesUntouched(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        [$pending]       = $this->pendingHostChanges();
        $identityMapSize = $this->em->getUnitOfWork()->size();

        $entry = $this->recorder->recordAndFlush('item.viewed', $this->alice, $this->bob, ['project_uuid' => 'p-1']);

        // Row written, with the user references and the scope.
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM kernel_action_log');
        $this->assertIsArray($row);
        $this->assertSame('item.viewed', $row['action']);
        $this->assertSame($this->alice->id, (int) $row['actor_id']);
        $this->assertSame($this->bob->id, (int) $row['subject_id']);
        $this->assertSame('p-1', $row['scope']);
        $this->assertSame('item.viewed', $entry->getAction());

        // Not flushed: neither the pending insert nor the pending update reached the database.
        $this->assertSame(['Draft'], $this->articleTitles());
        // Not cleared: the host unit of work still holds and schedules its own changes; the row is not in it.
        $uow = $this->em->getUnitOfWork();
        $this->assertTrue($this->em->contains($pending));
        $this->assertTrue($uow->isScheduledForInsert($pending));
        $this->assertTrue($this->em->contains($this->stored));
        $this->assertSame('Changed', $this->stored->title);
        $this->assertTrue($this->em->contains($this->alice));
        $this->assertFalse($this->em->contains($entry));
        $this->assertSame($identityMapSize, $uow->size());
        $this->assertTrue($this->em->isOpen());

        // The host flushes its own changes later, once; the action log row is not written twice.
        $this->em->flush();
        $this->assertSame(['Changed', 'Pending'], $this->articleTitles());
        $this->assertSame(1, $this->actionLogCount());
    }

    public function testIsolatedRecordIdsAndFlushLeavesPendingHostChangesUntouched(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        [$pending] = $this->pendingHostChanges();

        $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id, (string) $this->bob->id);

        $row = $this->em->getConnection()->fetchAssociative('SELECT actor_id, subject_id FROM kernel_action_log');
        $this->assertIsArray($row);
        $this->assertSame([(string) $this->alice->id, (string) $this->bob->id], [(string) $row['actor_id'], (string) $row['subject_id']]);
        $this->assertSame(['Draft'], $this->articleTitles());
        $this->assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
    }

    public function testIsolatedRowFollowsTheHostTransaction(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->assertSame(1, $this->actionLogCount());
        $connection->rollBack();

        $this->assertSame(0, $this->actionLogCount());
    }

    public function testIsolatedModeNeedsStoredUsersOfTheMappedClass(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $carol = new KernelUser('carol@example.com');
        $this->em->persist($carol);

        try {
            $this->recorder->recordAndFlush('item.viewed', $carol);
            $this->fail('An unflushed actor cannot be referenced by the isolated entity manager.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must already be stored', $e->getMessage());
        }

        try {
            $this->recorder->recordAndFlush('item.viewed', null, new stdClass());
            $this->fail('A subject of another class cannot be referenced.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must be an instance of', $e->getMessage());
        }

        // Nothing written, the host entity manager stays usable and keeps its pending user.
        $this->assertSame(0, $this->actionLogCount());
        $this->assertTrue($this->em->isOpen());
        $this->assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($carol));
    }

    /**
     * MK-B-L5: a persisted, not yet flushed actor whose id is already set (an assigned id such as a UUID) is not
     * stored either; a detached one is not known to exist. Both throw and nothing is written.
     */
    public function testIsolatedModeRejectsAssignedIdActorsNotFlushedYetAndDetachedOnes(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $dave     = new KernelUser('dave@example.com');
        $dave->id = 999;   // assigned before flush, like a UUID set in the constructor
        $this->em->persist($dave);

        try {
            $this->recorder->recordAndFlush('item.viewed', $dave);
            $this->fail('A persisted but unflushed actor with an assigned id must not be referenced.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must already be stored', $e->getMessage());
        }

        try {
            $this->recorder->recordAndFlush('item.viewed', null, new KernelUser('erin@example.com'));
            $this->fail('A new, unpersisted subject must not be referenced.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must already be stored', $e->getMessage());
        }

        $this->em->detach($this->bob);
        try {
            $this->recorder->recordAndFlush('item.viewed', $this->bob);
            $this->fail('A detached actor must not be referenced.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('must already be stored', $e->getMessage());
        }

        $this->assertSame(0, $this->actionLogCount());
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_user WHERE id = 999'));
        $this->assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($dave));

        // A managed, stored actor still works.
        $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->assertSame(1, $this->actionLogCount());
    }

    /** Kept-alive kernel (REQ-RUNTIME-002): repeated isolated writes keep the host unit of work and memory flat. */
    public function testRepeatedIsolatedWritesKeepTheHostUnitOfWorkAndMemoryFlat(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $size = $this->em->getUnitOfWork()->size();

        for ($i = 0; $i < 50; ++$i) {
            $this->recorder->recordAndFlush('item.viewed', $i % 2 === 0 ? $this->alice : $this->bob);
        }
        gc_collect_cycles();
        $baseline = memory_get_usage();

        for ($i = 0; $i < 500; ++$i) {
            $this->recorder->recordAndFlush('item.viewed', $i % 2 === 0 ? $this->alice : $this->bob, $i % 2 === 0 ? $this->bob : $this->alice);
        }
        gc_collect_cycles();

        $this->assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline);
        $this->assertSame($size, $this->em->getUnitOfWork()->size());
        $this->assertSame(550, $this->actionLogCount());
        $this->assertSame(275, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log WHERE actor_id = ?', [$this->bob->id]));
    }

    private function boot(string $flushMode): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');

        $this->kernel = new AuditKitTestKernel($flushMode);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $recorder = $this->kernel->getContainer()->get('test.action_log_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);
        $this->recorder = $recorder;

        $this->alice  = new KernelUser('alice@example.com');
        $this->bob    = new KernelUser('bob@example.com');
        $this->stored = new KernelArticle('Draft');
        $this->em->persist($this->alice);
        $this->em->persist($this->bob);
        $this->em->persist($this->stored);
        $this->em->flush();
    }

    /**
     * A pending insert and a pending update of the host, not flushed yet.
     *
     * @return array{KernelArticle}
     */
    private function pendingHostChanges(): array
    {
        $pending = new KernelArticle('Pending');
        $this->em->persist($pending);
        $this->stored->title = 'Changed';

        return [$pending];
    }

    /** @return list<string> */
    private function articleTitles(): array
    {
        /** @var list<string> $titles */
        $titles = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM kernel_article ORDER BY id');

        return $titles;
    }

    private function actionLogCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log');
    }
}
