<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\Change\ChangeSet;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Doctrine\TraceableActionLogMetadataListener;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_keys;
use function array_map;
use function getenv;
use function is_string;
use function strtolower;

/**
 * Since 2.2: the traceability columns on a real database (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; CI job "Databases").
 * The generated schema has the nullable columns and the `correlation_id` index; the correlation filter is exact even
 * under a case-insensitive collation; a row written by another open transaction with the same correlation id never
 * blocks the recorder (append-only, no unique key on the id).
 */
final class TraceabilityDbTest extends TestCase
{
    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private CorrelationIdHolder $holder;

    private ?Connection $other = null;

    protected function tearDown(): void
    {
        if ($this->other instanceof Connection) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testGeneratedSchemaHasTheNullableColumnsAndTheIndex(): void
    {
        $this->boot();

        $table = $this->em->getConnection()->createSchemaManager()->introspectTable('kernel_action_log');
        foreach (['correlation_id', 'caused_by', 'changes'] as $column) {
            $this->assertFalse($table->getColumn($column)->getNotnull(), $column . ' is nullable');
        }
        $this->assertSame(64, $table->getColumn('correlation_id')->getLength());

        $indexes = array_map(strtolower(...), array_keys($table->getIndexes()));
        $this->assertContains(TraceableActionLogMetadataListener::indexName('kernel_action_log'), $indexes);
    }

    public function testCorrelationFilterIsExactAndChangesRoundTrip(): void
    {
        $this->boot();
        $this->recordUnder('Corr-A', 'Parent-1', ChangeSet::between(['title' => 'Älter'], ['title' => 'Neu ✓', 'token' => 'x']));
        $this->recordUnder('corr-a', null, null);
        $this->recordUnder('CORR-A', null, null);

        $repository = $this->repository();
        $page       = $repository->pageByCriteria(new ActionLogCriteria(correlationId: 'Corr-A'));

        $this->assertCount(1, $page->items);
        $this->assertSame('Corr-A', $page->items[0]->correlationId);
        $this->assertSame('Parent-1', $page->items[0]->causedBy);
        // MySQL's JSON type reorders object keys: compare the content, not the order.
        $this->assertEquals(['title' => ['from' => 'Älter', 'to' => 'Neu ✓'], 'token' => ['changed' => true]], $page->items[0]->changes);
        $this->assertSame(1, $repository->countByCriteria(new ActionLogCriteria(correlationId: 'Corr-A')));
        $this->assertCount(1, $repository->findByCriteria(new ActionLogCriteria(correlationId: 'corr-a')));
        $this->assertSame(0, $repository->countByCriteria(new ActionLogCriteria(correlationId: 'Corr-B')));
    }

    public function testAnOpenTransactionWithTheSameCorrelationIdNeverBlocksTheRecorder(): void
    {
        $this->boot();
        $connection  = $this->em->getConnection();
        $other       = DriverManager::getConnection($connection->getParams());
        $this->other = $other;
        foreach ([$connection, $other] as $session) {
            $session->executeStatement($session->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }

        $other->beginTransaction();
        $row      = ['action' => 'item.viewed', 'context' => '[]', 'created_at' => '2026-01-01 10:00:00', 'correlation_id' => 'Corr-A'];
        $metadata = $this->em->getClassMetadata(KernelActionLog::class);
        if ($metadata->isIdGeneratorSequence()) {
            $platform  = $other->getDatabasePlatform();
            $row['id'] = $other->fetchOne($platform->getSequenceNextValSQL($metadata->getSequenceName($platform)));
        }
        $other->insert('kernel_action_log', $row);

        $this->recordUnder('Corr-A', null, null);
        $other->commit();

        $this->assertSame(2, $this->repository()->countByCriteria(new ActionLogCriteria(correlationId: 'Corr-A')));
    }

    private function recordUnder(string $id, ?string $causedBy, ?ChangeSet $changes): void
    {
        $this->holder->begin(new CorrelationContext(CorrelationId::fromString($id), $causedBy === null ? null : CorrelationId::fromString($causedBy)));

        try {
            $this->recorder->recordAndFlush('item.viewed', null, null, [], 'db', $changes);
        } finally {
            $this->holder->end();
        }
    }

    /**
     * @return ActionLogRepository<KernelActionLog>
     */
    private function repository(): ActionLogRepository
    {
        $repository = $this->em->getRepository(KernelActionLog::class);
        self::assertInstanceOf(ActionLogRepository::class, $repository);

        return $repository;
    }

    private function boot(): void
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }

        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');
        $this->kernel = new AuditKitTestKernel(ActionLogRecorder::FLUSH_ISOLATED, $url);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $schema   = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->holder   = new CorrelationIdHolder();
        $this->recorder = new ActionLogRecorder($registry, new RequestStack(), KernelActionLog::class, null, false, ActionLogRecorder::FLUSH_ISOLATED, null, null, $this->holder);
    }
}
