<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\AuditKitTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelArticle;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CapturesDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function getenv;
use function is_string;

/**
 * Since 1.18: the host-flush probe on a real database (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; CI job "Databases").
 * With `flush_mode` unset the probe reports the host's pending classes and the flush writes exactly what host mode
 * writes, inside and outside the host's transaction; the host's rollback still undoes everything.
 */
final class HostFlushProbeDbTest extends TestCase
{
    use CapturesDeprecations;

    private AuditKitTestKernel $kernel;

    private EntityManagerInterface $em;

    private ActionLogRecorder $recorder;

    private KernelUser $alice;

    private KernelArticle $stored;

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testProbeReportsAndTheHostFlushIsUnchanged(): void
    {
        $this->boot();
        $this->em->persist(new KernelArticle('Pending'));
        $this->stored->title = 'Changed';

        $messages = self::probeMessages(self::deprecationsOf(fn (): object => $this->recorder->recordAndFlush('item.viewed', $this->alice)));

        $this->assertSame(['Your pending changes to ' . KernelArticle::class . ' were flushed by NowoAuditKitBundle; 2.0 no longer does.'], $messages);
        $this->assertSame(['Changed', 'Pending'], $this->titles());
        $this->assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM kernel_action_log'));
    }

    public function testInsideTheHostTransactionTheRollbackStillUndoesEverything(): void
    {
        $this->boot();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->stored->title = 'Changed';

        $messages = self::probeMessages(self::deprecationsOf(fn (): object => $this->recorder->recordIdsAndFlush('item.viewed', $this->alice->id)));
        $this->assertSame(['Your pending changes to ' . KernelArticle::class . ' were flushed by NowoAuditKitBundle; 2.0 no longer does.'], $messages);
        $this->assertTrue($connection->isTransactionActive());
        $connection->rollBack();

        $this->assertSame(['Draft'], $this->titles());
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM kernel_action_log'));
    }

    private function boot(): void
    {
        $url = getenv('AUDIT_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set AUDIT_KIT_TEST_DATABASE_URL to run the real-database tests.');
        }

        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-test-kernel');
        $this->kernel = new AuditKitTestKernel(null, $url);
        self::deprecationsOf(fn () => $this->kernel->boot());

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

        $this->alice  = new KernelUser('alice@example.com');
        $this->stored = new KernelArticle('Draft');
        $em->persist($this->alice);
        $em->persist($this->stored);
        $em->flush();
    }

    /** @return list<string> */
    private function titles(): array
    {
        /** @var list<string> $titles */
        $titles = $this->em->getConnection()->fetchFirstColumn('SELECT title FROM kernel_article ORDER BY id');

        return $titles;
    }
}
