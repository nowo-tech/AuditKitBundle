<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\Change\ChangeSet;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Support\RecordingLogger;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Since 2.2: a 2.1-style log entity that does not implement TraceableActionLogInterface keeps working unchanged: no
 * column, no correlation id, changes dropped with a warning that never contains them, correlation filters match nothing.
 */
final class TraceabilityOptOutTest extends TestCase
{
    private DddTestKernel $kernel;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel');
        $this->kernel = new DddTestKernel();
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testRowsAreWrittenWithoutTraceabilityAndChangesAreDroppedWithAWarning(): void
    {
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('corr-1')));
        $logger   = new RecordingLogger();
        $recorder = new ActionLogRecorder($registry, new RequestStack(), AuditEntry::class, null, false, ActionLogRecorder::FLUSH_ISOLATED, $logger, null, $holder);

        $recorder->recordIdsAndFlush('item.viewed', 'u1', null, [], null, ChangeSet::between(['title' => 'secret-old'], ['title' => 'secret-new']));
        $recorder->recordIdsAndFlush('item.viewed', 'u1', null, [], null, new ChangeSet());

        $this->assertCount(1, $logger->records);
        $this->assertStringContainsString('changes dropped', (string) $logger->records[0][1]);
        $this->assertStringNotContainsString('secret', (string) json_encode($logger->records[0]));

        $reader = $this->kernel->getContainer()->get('test.action_log_reader');
        self::assertInstanceOf(ActionLogReaderInterface::class, $reader);
        $rows = $reader->pageByCriteria(new ActionLogCriteria())->items;
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->correlationId);
        $this->assertNull($rows[0]->changes);
        $this->assertSame([], $reader->pageByCriteria(new ActionLogCriteria(correlationId: 'corr-1'))->items);
        $this->assertSame(0, $reader->countByCriteria(new ActionLogCriteria(correlationId: 'corr-1')));

        $repository = $registry->getRepository(AuditEntry::class);
        $repository = $repository instanceof ActionLogRepository ? $repository : new ActionLogRepository($registry, AuditEntry::class);
        $this->assertSame([], $repository->findByCriteria(new ActionLogCriteria(correlationId: '')));
    }
}
