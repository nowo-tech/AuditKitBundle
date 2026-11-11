<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\ActionLog\Command\PurgeActionLogCommand;
use Nowo\AuditKitBundle\Blame\UserIdBlameValueResolver;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\DddTestKernel;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\AuditEntry;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\Invoice;
use Nowo\AuditKitBundle\Tests\Integration\Ddd\Domain\SecurityUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * Since 1.14, the DDD path end to end: XML-mapped domain without bundle or Doctrine attributes, `blame_mode: id`
 * without `user_class`, an id-mode action log mapped from the shipped XML. Real kernel, DoctrineBundle, SQLite.
 */
final class DddHostIntegrationTest extends TestCase
{
    private const ALICE = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f60';
    private const BOB   = '0192f1a4-7d3e-7c4b-9a1e-1b2c3d4e5f61';

    private DddTestKernel $kernel;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-ddd-kernel');

        $this->kernel = new DddTestKernel();
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testBootsWithoutUserClassAndWithoutUserRelations(): void
    {
        $container = $this->kernel->getContainer();
        $this->assertInstanceOf(UserIdBlameValueResolver::class, $container->get('test.blame_value_resolver'));
        $this->assertSame('id', $container->getParameter('nowo_audit_kit.blame_mode'));

        $config = $this->em->getConfiguration();

        $driver = $config->getMetadataDriverImpl();
        if ($driver instanceof BundleMappingDriver) {
            $driver = $driver->getDriver();
        }
        $this->assertInstanceOf(MappingDriverChain::class, $driver);
        foreach ($driver->getDrivers() as $namespace => $nested) {
            $this->assertInstanceOf(SimplifiedXmlDriver::class, $nested, $namespace . ' is mapped in XML');
        }

        $superclass = $this->em->getClassMetadata(AbstractIdActionLog::class);
        $this->assertTrue($superclass->isMappedSuperclass);

        $entry = $this->em->getClassMetadata(AuditEntry::class);
        $this->assertSame([], $entry->getAssociationNames());
        $this->assertSame('actor_id', $entry->getColumnName('actorId'));
        $this->assertSame('subject_id', $entry->getColumnName('subjectId'));
        $this->assertSame('created_at', $entry->getColumnName('createdAt'));
        $this->assertSame(64, $entry->getFieldMapping('actorId')['length']);
    }

    public function testBlameStoresTheSecurityUserIdNeverItsIdentifier(): void
    {
        $this->login(self::ALICE, 'alice@example.com');
        $invoice = new Invoice('inv-1', 'Draft');
        $this->em->persist($invoice);
        $this->em->flush();

        $this->login(self::BOB, 'bob@example.com');
        $invoice->rename('Final');
        $this->em->flush();
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM ddd_invoice WHERE id = ?', ['inv-1']);
        $this->assertIsArray($row);
        $this->assertSame(self::ALICE, $row['created_by']);
        $this->assertSame(self::BOB, $row['updated_by'], 'no state leaks from the previous user (kept-alive kernel)');
        $this->assertSame('2026-01-01 10:00:00', $row['created_at']);
        $this->assertNotNull($row['updated_at']);
    }

    public function testAnonymousRequestLeavesBlameNull(): void
    {
        $this->em->persist(new Invoice('inv-2', 'Anonymous'));
        $this->em->flush();

        $row = $this->em->getConnection()->fetchAssociative('SELECT created_by, created_at FROM ddd_invoice WHERE id = ?', ['inv-2']);
        $this->assertIsArray($row);
        $this->assertNull($row['created_by']);
        $this->assertNotNull($row['created_at']);
    }

    public function testActionLogRecordsQueriesAndPurgesByUserId(): void
    {
        $recorder = $this->kernel->getContainer()->get('test.action_log_id_recorder');
        $this->assertInstanceOf(ActionLogRecorder::class, $recorder);

        $entry = $recorder->recordIdsAndFlush('invoice.sent', Uuid::fromString(self::ALICE), self::BOB, ['invoice' => 'inv-1'], 'tenant-a');
        $this->assertInstanceOf(AuditEntry::class, $entry);
        $this->assertSame(self::ALICE, $entry->getActorId());
        $recorder->recordIds('invoice.viewed', self::BOB);
        $recorder->recordIds('system.cleanup');
        $this->em->flush();
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM ddd_audit_entry WHERE action = ?', ['invoice.sent']);
        $this->assertIsArray($row);
        $this->assertSame(self::ALICE, $row['actor_id']);
        $this->assertSame(self::BOB, $row['subject_id']);
        $this->assertSame('tenant-a', $row['scope']);

        $registry = $this->kernel->getContainer()->get('doctrine');
        $this->assertInstanceOf(ManagerRegistry::class, $registry);
        $repository = new ActionLogRepository($registry, AuditEntry::class);
        $this->assertCount(1, $repository->findByCriteria(new ActionLogCriteria(actor: self::ALICE)));
        $this->assertCount(1, $repository->findByCriteria(new ActionLogCriteria(actor: new SecurityUser(Uuid::fromString(self::ALICE), 'alice@example.com'))));
        $this->assertCount(1, $repository->findByCriteria(new ActionLogCriteria(subject: self::BOB)));
        $this->assertCount(2, $repository->findInvolving(self::BOB));
        $this->assertSame(0, $repository->countByCriteria(new ActionLogCriteria(actor: '')));
        $this->assertSame(3, $repository->countByCriteria(new ActionLogCriteria()));
        $latest = $repository->findLatest();
        $this->assertSame('system.cleanup', $latest[0]->getAction());
        $this->assertNull($latest[0]->getActorId());

        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find(PurgeActionLogCommand::NAME));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--all-actions' => true, '--older-than-days' => '1']));
        $this->assertStringContainsString('0 row(s)', $tester->getDisplay());
    }

    public function testRecordWithUserObjectsPointsToRecordIds(): void
    {
        $recorder = $this->kernel->getContainer()->get('test.action_log_id_recorder');
        $this->assertInstanceOf(ActionLogRecorder::class, $recorder);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('references users by id: record rows with ' . ActionLogIdRecorderInterface::class . '::recordIds()');

        $recorder->record('invoice.sent');
    }

    private function login(string $id, string $email): void
    {
        $storage = $this->kernel->getContainer()->get('security.token_storage');
        $this->assertInstanceOf(TokenStorage::class, $storage);
        $storage->setToken(new UsernamePasswordToken(new SecurityUser(Uuid::fromString($id), $email), 'main', ['ROLE_USER']));
    }
}
