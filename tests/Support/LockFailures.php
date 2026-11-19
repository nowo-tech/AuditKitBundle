<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** DBAL storage errors as drivers raise them (DBAL 3 and 4), for the lock-conflict tests (REQ-RUNTIME-003). */
final class LockFailures
{
    public static function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driver('Deadlock found when trying to get lock', '40001', 1213), null);
    }

    public static function lockWaitTimeout(): LockWaitTimeoutException
    {
        return new LockWaitTimeoutException(self::driver('Lock wait timeout exceeded', 'HY000', 1205), null);
    }

    /** PostgreSQL: could not serialize access (SQLSTATE 40001). */
    public static function serializationFailure(): DriverException
    {
        return new DriverException(self::driver('could not serialize access due to concurrent update', '40001', 7), null);
    }

    public static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(self::driver('Duplicate entry', '23000', 1062), null);
    }

    public static function connectionLost(): ConnectionLost
    {
        return new ConnectionLost(self::driver('MySQL server has gone away', 'HY000', 2006), null);
    }

    private static function driver(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
