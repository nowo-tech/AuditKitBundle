<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Nowo\AuditKitBundle\Doctrine\KeysetDelete;
use PHPUnit\Framework\TestCase;

use function count;

/** MySQL platform SQL and driver-specific id values (numeric strings), on a stub connection. */
final class KeysetDeleteTest extends TestCase
{
    public function testMySqlStatementsAndNumericStringIds(): void
    {
        $selects = [];
        $deletes = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $params, array $types) use (&$selects): array {
                $selects[] = [$sql, $params, $types];

                return [['1', '7'], ['9']][count($selects) - 1];
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params, array $types) use (&$deletes): string {
                $deletes[] = [$sql, $params, $types];

                return (string) count($params[0]);
            },
        );

        $deleted = KeysetDelete::run($connection, '`user_action`', '`created_at` < :before', ['before' => '2026-01-01'], ['before' => 'datetime_immutable'], 2, '`id`');

        $this->assertSame(3, $deleted);
        $this->assertSame([
            ['SELECT `id` FROM `user_action` WHERE (`created_at` < :before) ORDER BY `id` ASC LIMIT 2', ['before' => '2026-01-01'], ['before' => 'datetime_immutable']],
            [
                'SELECT `id` FROM `user_action` WHERE (`created_at` < :before) AND `id` > :keysetDeleteAfterId ORDER BY `id` ASC LIMIT 2',
                ['before' => '2026-01-01', 'keysetDeleteAfterId' => 7],
                ['before' => 'datetime_immutable', 'keysetDeleteAfterId' => ParameterType::INTEGER],
            ],
        ], $selects);
        $this->assertSame([
            ['DELETE FROM `user_action` WHERE `id` IN (?)', [[1, 7]], [ArrayParameterType::INTEGER]],
            ['DELETE FROM `user_action` WHERE `id` IN (?)', [[9]], [ArrayParameterType::INTEGER]],
        ], $deletes);
    }

    public function testStringIdsUseStringTypes(): void
    {
        $selects = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $params, array $types) use (&$selects): array {
                $selects[] = [$params, $types];

                return [['01J0', '01J1'], []][count($selects) - 1];
            },
        );
        $connection->expects($this->once())->method('executeStatement')
            ->with('DELETE FROM t WHERE uuid IN (?)', [['01J0', '01J1']], [ArrayParameterType::STRING])
            ->willReturn(2);

        $this->assertSame(2, KeysetDelete::run($connection, 't', 'n = ?', [1], [], 2, 'uuid'));
        $this->assertSame([[[1], []], [[1, '01J1'], [1 => ParameterType::STRING]]], $selects);
    }
}
