<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function getenv;
use function is_string;

/**
 * REQ-RUNTIME-003: the recorder and the keyset purge against a second connection on a real database (MySQL 8.4,
 * MariaDB 11.4, PostgreSQL 16; CI job "Databases"). Both sessions wait at most 1 s for a lock, so a regression fails
 * fast instead of hanging. Each case asserts the answer, the stored rows and that the connection is usable afterwards.
 *
 * Set AUDIT_KIT_TEST_DATABASE_URL (a Doctrine URL) to run them; CI runs them with --fail-on-skipped.
 */
final class ConcurrentWritesDbTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private Connection $other;

    private KernelUser $alice;

    protected function tearDown(): void
    {
        if (isset($this->other)) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /** Append-only: a row inserted by another open transaction never blocks or duplicates the recorder's row. */
    public function testTwoWritersOfTheSameActorBothStoreTheirRow(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $this->other->beginTransaction();
        $this->other->insert('kernel_action_log', [
            'action'     => 'item.viewed',
            'actor_id'   => $this->alice->id,
            'context'    => '[]',
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->other->commit();

        $this->assertSame(2, $this->rows('actor_id = ' . (int) $this->alice->id));
    }

    /**
     * Isolated mode: the row waits for a lock on its actor (the FK check), then gives up after the session's lock
     * timeout. Never replayed (a log write never waits twice), CONFLICT_001, nothing written, and the same connection
     * writes the row once the lock is gone.
     */
    public function testAnIsolatedWriteBlockedByALockIsAConflictAndWritesNothing(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $this->lockAlice();

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The write must give up after the lock timeout.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(1, $conflict->attempts);
            $this->assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
            $this->assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
        }

        $this->other->rollBack();
        $this->assertSame(0, $this->rows());
        $this->assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        $this->assertTrue($this->em->isOpen());

        $this->recorder->recordAndFlush('item.viewed', $this->alice);
        $this->assertSame(1, $this->rows());
    }

    /**
     * Host mode: the row belongs to the host's flush, which fails as a whole with the storage error unchanged (the host
     * owns the unit). The ORM closes the host manager; the next record replaces it and writes.
     */
    public function testAHostFlushBlockedByALockFailsAsAWholeAndTheClosedManagerIsReplaced(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $this->em->persist(new KernelUser('bob@example.com'));
        $this->lockAlice();

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The host flush must fail after the lock timeout.');
        } catch (Throwable $failure) {
            // The storage error itself (PostgreSQL 55P03 is a plain DriverException in DBAL), never wrapped.
            $this->assertInstanceOf(DriverException::class, $failure);
            $this->assertSame(TransientFailureKind::LockWaitTimeout, (new TransientFailureClassifier())->classify($failure));
        }

        $this->other->rollBack();
        $this->assertFalse($this->em->isOpen());
        $this->assertSame(0, $this->rows());
        // Atomic with the host's work: the host's pending user was not written either.
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM kernel_user WHERE email = 'bob@example.com'"));

        $this->recorder->recordIdsAndFlush('item.failed');
        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->assertTrue($em->isOpen());
        $this->assertSame(1, $this->rows());
    }

    /** Purge blocked on its second batch: CONFLICT_002 (the first batch stays deleted), then a rerun finishes the job. */
    public function testAPurgeBlockedAfterADeletedBatchIsCommittedAndARerunCompletes(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $ids = $this->seedOldRows(5);
        $this->lockRow($ids[2]);

        try {
            $this->repository()->purgeBefore(new DateTimeImmutable('2026-01-01'), null, 2);
            $this->fail('The purge must give up on the locked batch.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertTrue($conflict->committed);
            $this->assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
            $this->assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
        }

        $this->assertSame(3, $this->rows());
        $this->other->rollBack();

        $this->assertSame(3, $this->repository()->purgeBefore(new DateTimeImmutable('2026-01-01'), null, 2));
        $this->assertSame(0, $this->rows());
    }

    /** Purge blocked on its first batch: CONFLICT_001, nothing deleted. */
    public function testAPurgeBlockedBeforeAnyDeleteIsRetrySafe(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $ids = $this->seedOldRows(3);
        $this->lockRow($ids[0]);

        try {
            $this->repository()->purgeBefore(new DateTimeImmutable('2026-01-01'), null, 2);
            $this->fail('The purge must give up on the locked batch.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertFalse($conflict->committed);
            $this->assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
        }

        $this->assertSame(3, $this->rows());
        $this->other->rollBack();
        $this->assertSame(3, $this->repository()->purgeBefore(new DateTimeImmutable('2026-01-01'), null, 2));
    }

    /** New rows inserted by another transaction while the purge runs are never deleted (they are not old). */
    public function testRowsWrittenDuringAPurgeAreKept(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $this->seedOldRows(3);
        $this->other->beginTransaction();
        $this->other->insert('kernel_action_log', ['action' => 'item.viewed', 'context' => '[]', 'created_at' => '2026-06-01 10:00:00']);

        $this->assertSame(3, $this->repository()->purgeBefore(new DateTimeImmutable('2026-01-01'), null, 2));
        $this->other->commit();

        $this->assertSame(1, $this->rows());
    }

    private function boot(string $flushMode): void
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }

        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');
        $this->kernel = new AuditKitTestKernel($flushMode, $url);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $recorder = $this->kernel->getContainer()->get('test.action_log_recorder');
        self::assertInstanceOf(ActionLogRecorder::class, $recorder);
        $this->recorder = $recorder;

        $this->alice = new KernelUser('alice@example.com');
        $em->persist($this->alice);
        $em->flush();

        $connection  = $em->getConnection();
        $this->other = DriverManager::getConnection($connection->getParams());
        $this->shortLockTimeout($connection);
        $this->shortLockTimeout($this->other);
    }

    private function shortLockTimeout(Connection $connection): void
    {
        $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "SET lock_timeout = '1s'"
            : 'SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** The other connection holds a row lock on alice (as a host updating its user would). */
    private function lockAlice(): void
    {
        $this->other->beginTransaction();
        $this->other->fetchOne('SELECT id FROM kernel_user WHERE id = ? FOR UPDATE', [$this->alice->id]);
    }

    private function lockRow(int $id): void
    {
        $this->other->beginTransaction();
        $this->other->fetchOne('SELECT id FROM kernel_action_log WHERE id = ? FOR UPDATE', [$id]);
    }

    /** @return list<int> ids of the rows, in order */
    private function seedOldRows(int $rows): array
    {
        for ($i = 0; $i < $rows; ++$i) {
            $entry = $this->recorder->record('item.viewed', $this->alice);
            self::assertInstanceOf(KernelActionLog::class, $entry);
            $entry->backdate('-1 year');
        }
        $this->em->flush();
        $this->em->clear();

        /** @var list<int|string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM kernel_action_log ORDER BY id');

        return array_map(intval(...), $ids);
    }

    /** @return ActionLogRepository<KernelActionLog> */
    private function repository(): ActionLogRepository
    {
        $repository = $this->em->getRepository(KernelActionLog::class);
        self::assertInstanceOf(ActionLogRepository::class, $repository);

        return $repository;
    }

    private function rows(string $where = '1 = 1'): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log WHERE ' . $where);
    }
}
