<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Nowo\AuditKitBundle\Doctrine\KeysetDelete;
use Nowo\AuditKitBundle\Tests\Support\LockFailures;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_shift;

/**
 * REQ-RUNTIME-003 (since 1.17): lock conflicts of a keyset purge, on a stub connection. Outside a transaction a
 * deadlock or serialization failure replays the batch; what remains is a {@see ConcurrencyConflict} whose code says
 * whether batches were already deleted. Inside a transaction, and for errors that are not lock conflicts, the
 * storage error is rethrown unchanged.
 */
final class KeysetDeleteConflictTest extends TestCase
{
    /** @return iterable<string, array{Throwable}> */
    public static function replayedFailures(): iterable
    {
        yield 'deadlock' => [LockFailures::deadlock()];
        yield 'serialization failure' => [LockFailures::serializationFailure()];
    }

    #[DataProvider('replayedFailures')]
    public function testReplaysABatchAfterADeadlockOrSerializationFailure(Throwable $failure): void
    {
        $deletes    = 0;
        $connection = $this->connection(false, [[1, 2], [3]], static function () use (&$deletes, $failure): int {
            ++$deletes;
            if ($deletes <= KeysetDelete::MAX_BATCH_REPLAYS) {
                throw $failure;
            }

            return 2;
        }, $selects);

        // Two replays of the first batch, then the batch and the short last batch.
        $this->assertSame(4, KeysetDelete::run($connection, 't', 'n = ?', [1], [], 2));
        $this->assertSame(KeysetDelete::MAX_BATCH_REPLAYS + 2, $deletes);
        $this->assertSame([[1], [1], [1], [1, 2]], $selects);
    }

    public function testAConflictThatRemainsBeforeAnyDeleteIsRetrySafe(): void
    {
        $connection = $this->connection(false, [[1, 2]], static fn (): int => throw LockFailures::deadlock(), $selects);

        try {
            KeysetDelete::run($connection, 't', '', [], [], 2);
            $this->fail('A conflict that remains must be thrown.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(KeysetDelete::MAX_BATCH_REPLAYS + 1, $conflict->attempts);
            $this->assertSame(TransientFailureKind::Deadlock, $conflict->kind);
            $this->assertFalse($conflict->committed);
            $this->assertSame(KeysetDelete::RETRY_AFTER_SECONDS, $conflict->retryAfterSeconds());
            $this->assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($conflict));
            $this->assertInstanceOf(Throwable::class, $conflict->getPrevious());
        }
    }

    public function testALockWaitTimeoutIsNeverReplayedAndAfterADeletedBatchItIsCommitted(): void
    {
        $deletes    = 0;
        $connection = $this->connection(false, [[1, 2], [3, 4]], static function () use (&$deletes): int {
            ++$deletes;
            if ($deletes === 2) {
                throw LockFailures::lockWaitTimeout();
            }

            return 2;
        }, $selects);

        try {
            KeysetDelete::run($connection, 't', '', [], [], 2);
            $this->fail('A lock wait timeout must be thrown.');
        } catch (ConcurrencyConflict $conflict) {
            $this->assertSame(1, $conflict->attempts);
            $this->assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
            $this->assertTrue($conflict->committed);
            $this->assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
        }

        $this->assertSame(2, $deletes);
    }

    public function testInsideATransactionTheConflictIsRethrownUnchanged(): void
    {
        $deadlock   = LockFailures::deadlock();
        $connection = $this->connection(true, [[1]], static fn (): int => throw $deadlock, $selects);

        try {
            KeysetDelete::run($connection, 't', '', [], [], 2);
            $this->fail('The deadlock must be rethrown.');
        } catch (Throwable $thrown) {
            $this->assertSame($deadlock, $thrown);
        }
    }

    /** @return iterable<string, array{Throwable}> */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [LockFailures::uniqueViolation()];
        yield 'lost connection' => [LockFailures::connectionLost()];
    }

    #[DataProvider('notConflicts')]
    public function testOtherStorageErrorsAreNotConflicts(Throwable $failure): void
    {
        $connection = $this->connection(false, [[1]], static fn (): int => throw $failure, $selects);

        try {
            KeysetDelete::run($connection, 't', '', [], [], 2);
            $this->fail('The storage error must be rethrown.');
        } catch (Throwable $thrown) {
            $this->assertSame($failure, $thrown);
        }
    }

    /**
     * @param list<list<int>> $batches ids returned by successive selects
     * @param callable(): int $delete
     * @param list<list<mixed>>|null $selects parameters of each select, filled by reference
     *
     * @param-out list<list<mixed>> $selects
     */
    private function connection(bool $inTransaction, array $batches, callable $delete, ?array &$selects): Connection
    {
        $selects    = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('isTransactionActive')->willReturn($inTransaction);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $params) use (&$selects, &$batches): array {
                $selects[] = $params;

                // A replayed batch selects the same ids again: the next batch is served once the delete succeeded.
                return $batches[0] ?? [];
            },
        );
        $connection->method('executeStatement')->willReturnCallback(static function () use ($delete, &$batches): int {
            $result = $delete();
            array_shift($batches);

            return $result;
        });

        return $connection;
    }
}
