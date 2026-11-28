<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\Doctrine\TraceableActionLogMetadataListener;
use Nowo\AuditKitBundle\Tests\Fixtures\Traceable\PlainIdLog;
use Nowo\AuditKitBundle\Tests\Fixtures\Traceable\TraceableIdLog;
use Nowo\AuditKitBundle\Tests\Fixtures\Traceable\TraceableRenamedLog;
use Nowo\AuditKitBundle\Tests\Fixtures\Traceable\TraceableStiChild;
use Nowo\AuditKitBundle\Tests\Fixtures\Traceable\TraceableStiRoot;
use PHPUnit\Framework\TestCase;

use const PHP_VERSION_ID;

final class TraceableActionLogMetadataListenerTest extends TestCase
{
    public function testMapsTheThreeColumnsAndTheCorrelationIndex(): void
    {
        $em       = $this->entityManager();
        $metadata = $em->getClassMetadata(TraceableIdLog::class);

        $this->assertSame('correlation_id', $metadata->getColumnName('correlationId'));
        $this->assertSame('caused_by', $metadata->getColumnName('causedBy'));
        $this->assertSame('changes', $metadata->getColumnName('changes'));
        $this->assertSame('json', $metadata->getTypeOfField('changes'));
        $this->assertTrue($metadata->isNullable('correlationId'));
        $this->assertSame(64, $metadata->getFieldMapping('causedBy')->length);

        $table = (new SchemaTool($em))->getSchemaFromMetadata([$metadata])->getTable('tr_id_log');
        $index = $table->getIndex(TraceableActionLogMetadataListener::indexName('tr_id_log'));
        $this->assertSame(['correlation_id'], $index->getColumns());
        $this->assertTrue($table->getColumn('changes')->getNotnull() === false);
    }

    public function testAFieldTheEntityMapsAndItsOwnIndexAreKept(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(TraceableRenamedLog::class);

        $this->assertSame('request_correlation', $metadata->getColumnName('correlationId'));
        $this->assertSame('caused_by', $metadata->getColumnName('causedBy'));
        $this->assertSame(['idx_tr_renamed_corr_created'], array_keys($metadata->table['indexes'] ?? []));
    }

    public function testSingleTableInheritanceIndexesTheRootOnce(): void
    {
        $em = $this->entityManager();

        $this->assertArrayHasKey(TraceableActionLogMetadataListener::indexName('tr_sti_log'), $em->getClassMetadata(TraceableStiRoot::class)->table['indexes'] ?? []);
        $this->assertTrue($em->getClassMetadata(TraceableStiChild::class)->hasField('correlationId'));
    }

    public function testEntitiesThatDoNotOptInAndSuperclassesAreUntouched(): void
    {
        $em = $this->entityManager();

        $this->assertFalse($em->getClassMetadata(PlainIdLog::class)->hasField('correlationId'));
        $this->assertFalse($em->getClassMetadata(AbstractIdActionLog::class)->hasField('correlationId'));
    }

    private function entityManager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/../../Fixtures/Traceable', __DIR__ . '/../../../src/ActionLog']));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-audit-kit-traceable-proxies');
        $config->setProxyNamespace('NowoAuditKitTraceableProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TraceableActionLogMetadataListener());

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
    }
}
