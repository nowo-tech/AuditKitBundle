<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\LockFailures;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/**
 * REQ-RUNTIME-003 (since 1.17): lock conflicts of the recorder, injected at the flush of the action log row
 * (in-memory SQLite kernel; the real two-connection races are in tests/Database).
 *
 * - isolated mode, outside a transaction: a deadlock or serialization failure is replayed on a new entity manager
 *   (one row, never two); what remains is a ConcurrencyConflict (CONFLICT_001: the row was not written);
 * - isolated mode, inside the host's transaction: never replayed, the connection is back at the host's level;
 * - host mode: the host's unit owns the failure, rethrown unchanged;
 * - unique violations are not conflicts; a closed host entity manager is replaced before writing.
 */
final class ActionLogConflictTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private KernelUser $alice;

    /** @var list<Throwable> failures thrown, in order, by the next flushes that write an action log row */
    private array $failures = [];

    private int $flushes = 0;

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testIsolatedDeadlocksAreReplayedAndWriteOneRow(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $pending = new KernelArticle('Pending');
        $this->em->persist($pending);
        $this->failures = [LockFailures::deadlock(), LockFailures::serializationFailure()];

        $entry = $this->recorder->recordAndFlush('item.viewed', $this->alice);

        $this->assertSame('item.viewed', $entry->getAction());
        $this->assertSame(3, $this->flushes);
        $this->assertSame(1, $this->actionLogCount());
        // The host's unit of work is untouched by the replays.
        $this->assertTrue($this->em->isOpen());
        $this->assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        $this->assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }

    public function testAnIsolatedConflictThatRemainsIsRetrySafe(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $this->failures = [LockFailures::deadlock(), LockFailures::deadlock(), LockFailures::deadlock()];

        try {
            $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id);
            $this->fail('A conflict that remains must be thrown.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(ActionLogRecorder::MAX_ISOLATED_REPLAYS + 1, $conflict->attempts);
            $this->assertSame(TransientFailureKind::Deadlock, $conflict->kind);
            $this->assertFalse($conflict->committed);
            $this->assertSame(ActionLogRecorder::RETRY_AFTER_SECONDS, $conflict->retryAfterSeconds());
            $this->assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
            $this->assertInstanceOf(DeadlockException::class, $conflict->getPrevious());
        }

        $this->assertSame(0, $this->actionLogCount());
        $this->assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());

        // The connection is usable afterwards.
        $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id);
        $this->assertSame(1, $this->actionLogCount());
    }

    public function testALockWaitTimeoutIsNotReplayed(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $this->failures = [LockFailures::lockWaitTimeout()];

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The lock wait timeout must be thrown.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(1, $conflict->attempts);
            $this->assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
        }

        $this->assertSame(1, $this->flushes);
        $this->assertSame(0, $this->actionLogCount());
    }

    public function testInsideTheHostTransactionAConflictIsNotReplayed(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->failures = [LockFailures::deadlock()];

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The deadlock must be thrown.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(1, $conflict->attempts);
        }

        $this->assertSame(1, $this->flushes);
        // Back at the host's level: the host decides (here it still commits its own work).
        $this->assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();
        $this->assertSame(0, $this->actionLogCount());
    }

    public function testAUniqueViolationIsNotAConflict(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_ISOLATED);
        $violation      = LockFailures::uniqueViolation();
        $this->failures = [$violation];

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The unique violation must be rethrown unchanged.');
        } catch (Throwable $thrown) {
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
            $this->assertSame($violation, $thrown);
        }

        $this->assertSame(1, $this->flushes);
    }

    public function testHostModeRethrowsTheConflictUnchanged(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $deadlock       = LockFailures::deadlock();
        $this->failures = [$deadlock];

        try {
            $this->recorder->recordAndFlush('item.viewed', $this->alice);
            $this->fail('The host owns the unit: the deadlock must reach it unchanged.');
        } catch (Throwable $thrown) {
            $this->assertSame($deadlock, $thrown);
        }

        $this->assertSame(1, $this->flushes);
        $this->assertSame(0, $this->actionLogCount());
    }

    public function testAClosedHostEntityManagerIsReplacedBeforeWriting(): void
    {
        $this->boot(ActionLogRecorder::FLUSH_HOST);
        $this->em->close();

        $this->recorder->recordAndFlush('item.failed');

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->assertTrue($em->isOpen());
        $this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log'));

        // record() alone (the host flushes) also writes into the replaced manager.
        $em->close();
        $this->recorder->recordIds('item.failed');
        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();
        $this->assertSame(2, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log'));
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

        $this->alice = new KernelUser('alice@example.com');
        $this->em->persist($this->alice);
        $this->em->flush();

        // The isolated manager shares this event manager: the listener sees both modes' flushes of a log row.
        $this->em->getEventManager()->addEventListener(Events::onFlush, $this);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof KernelActionLog) {
                ++$this->flushes;
                $failure = array_shift($this->failures);
                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                return;
            }
        }
    }

    private function actionLogCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log');
    }
}
