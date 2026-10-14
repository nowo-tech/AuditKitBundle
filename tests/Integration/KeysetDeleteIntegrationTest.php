<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use ArrayObject;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Nowo\AuditKitBundle\Doctrine\KeysetDelete;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;

/** Plain DBAL on in-memory SQLite: batches, keyset, empty table, positional / named / array parameters, string ids. */
final class KeysetDeleteIntegrationTest extends TestCase
{
    private Connection $connection;

    /** @var ArrayObject<int, string> SQL of every executed statement */
    private ArrayObject $queries;

    protected function setUp(): void
    {
        $this->queries = new ArrayObject();
        $logger        = new class($this->queries) extends AbstractLogger {
            /** @param ArrayObject<int, string> $queries */
            public function __construct(private readonly ArrayObject $queries)
            {
            }

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->queries->append($context['sql']);
                }
            }
        };

        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new Middleware($logger)]),
        );
        $this->connection->executeStatement('CREATE TABLE item (id INTEGER PRIMARY KEY, kind VARCHAR(16) NOT NULL, n INTEGER NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE token (code VARCHAR(36) PRIMARY KEY, n INTEGER NOT NULL)');
    }

    public function testEmptyTableRunsOneSelectAndNoDelete(): void
    {
        $this->queries->exchangeArray([]);

        $this->assertSame(0, KeysetDelete::run($this->connection, 'item', 'n > ?', [0]));
        $this->assertCount(1, $this->queries);
        $this->assertSame('SELECT id FROM item WHERE (n > ?) ORDER BY id ASC LIMIT 1000', $this->queries[0]);
    }

    public function testDeletesMoreRowsThanOneBatchAndKeepsTheRest(): void
    {
        $this->seedItems(25);
        $this->queries->exchangeArray([]);

        // Odd n only (13 rows), batch of 5: 5 + 5 + 3 → three selects, three deletes, stop on the short batch.
        $deleted = KeysetDelete::run($this->connection, 'item', 'n % 2 = ?', [1], [ParameterType::INTEGER], 5);
        $queries = $this->queries->getArrayCopy();

        $this->assertSame(13, $deleted);
        $this->assertSame(12, $this->itemCount());
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM item WHERE n % 2 = 1'));
        $this->assertCount(6, $queries);
        $this->assertSame('SELECT id FROM item WHERE (n % 2 = ?) ORDER BY id ASC LIMIT 5', $queries[0]);
        $this->assertSame('DELETE FROM item WHERE id IN (?, ?, ?, ?, ?)', $queries[1]);
        $this->assertSame('SELECT id FROM item WHERE (n % 2 = ?) AND id > ? ORDER BY id ASC LIMIT 5', $queries[2]);
        $this->assertSame('DELETE FROM item WHERE id IN (?, ?, ?)', $queries[5]);
    }

    public function testExactMultipleOfBatchSizeEndsWithAnEmptySelect(): void
    {
        $this->seedItems(6);
        $this->queries->exchangeArray([]);

        $this->assertSame(6, KeysetDelete::run($this->connection, 'item', '', batchSize: 3));
        $queries = $this->queries->getArrayCopy();

        $this->assertSame(0, $this->itemCount());
        $this->assertCount(5, $queries);
        $this->assertSame('SELECT id FROM item ORDER BY id ASC LIMIT 3', $queries[0]);
        $this->assertSame('SELECT id FROM item WHERE id > ? ORDER BY id ASC LIMIT 3', $queries[2]);
        $this->assertSame('SELECT id FROM item WHERE id > ? ORDER BY id ASC LIMIT 3', $queries[4]);
    }

    public function testNamedAndArrayParameters(): void
    {
        $this->seedItems(12);

        $deleted = KeysetDelete::run(
            $this->connection,
            'item',
            'kind IN (:kinds) AND n >= :min',
            ['kinds' => ['a', 'c'], 'min' => 3],
            ['kinds' => ArrayParameterType::STRING, 'min' => ParameterType::INTEGER],
            2,
        );

        // kinds cycle a, b, c: a / c with n >= 3 → n ∈ {3, 4, 6, 7, 9, 10, 12}.
        $this->assertSame(7, $deleted);
        $this->assertSame([1, 2, 5, 8, 11], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT n FROM item ORDER BY n')));
    }

    public function testPositionalArrayParameters(): void
    {
        $this->seedItems(9);

        $deleted = KeysetDelete::run(
            $this->connection,
            'item',
            'kind = ? AND n IN (?)',
            ['b', [2, 5, 6, 8]],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
            1,
        );

        $this->assertSame(3, $deleted);
        $this->assertSame(6, $this->itemCount());
    }

    public function testBatchSizeBelowOneIsClampedToOne(): void
    {
        $this->seedItems(3);

        $this->assertSame(3, KeysetDelete::run($this->connection, 'item', '1 = 1', batchSize: 0));
        $this->assertSame(0, $this->itemCount());
    }

    public function testStringPrimaryKey(): void
    {
        foreach (['0a', '1b', '2c', '3d', '4e'] as $n => $code) {
            $this->connection->insert('token', ['code' => $code, 'n' => $n]);
        }

        $this->assertSame(4, KeysetDelete::run($this->connection, 'token', 'n <> :keep', ['keep' => 2], batchSize: 2, idColumn: 'code'));
        $this->assertSame(['2c'], $this->connection->fetchFirstColumn('SELECT code FROM token'));
    }

    public function testReservedParameterNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"keysetDeleteAfterId" is reserved');

        KeysetDelete::run($this->connection, 'item', 'id > :keysetDeleteAfterId', [KeysetDelete::AFTER_ID_PARAMETER => 1]);
    }

    public function testReservedTypeNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        KeysetDelete::run($this->connection, 'item', 'n > :n', ['n' => 1], [KeysetDelete::AFTER_ID_PARAMETER => ParameterType::INTEGER]);
    }

    private function seedItems(int $count): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $this->connection->insert('item', ['id' => $n * 10, 'kind' => ['a', 'b', 'c'][($n - 1) % 3], 'n' => $n]);
        }
    }

    private function itemCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM item');
    }
}
