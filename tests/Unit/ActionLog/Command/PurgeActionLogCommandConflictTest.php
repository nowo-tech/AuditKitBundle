<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog\Command;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\ActionLog\Command\PurgeActionLogCommand;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Support\LockFailures;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** REQ-RUNTIME-003 (since 1.17): the purge command reports a lock conflict with its code and exit code 75. */
final class PurgeActionLogCommandConflictTest extends TestCase
{
    public function testNothingDeletedIsConflict001(): void
    {
        $tester = $this->tester(new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, LockFailures::deadlock()));

        $this->assertSame(PurgeActionLogCommand::EXIT_CONFLICT, $tester->execute(['--all-actions' => true]));
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('CONFLICT_001', $display);
        $this->assertStringContainsString('(deadlock)', $display);
        $this->assertStringContainsString('Nothing was deleted', $display);
    }

    public function testDeletedBatchesAreConflict002(): void
    {
        $tester = $this->tester(new ConcurrencyConflict(1, TransientFailureKind::LockWaitTimeout, 1, LockFailures::lockWaitTimeout(), true));

        $this->assertSame(PurgeActionLogCommand::EXIT_CONFLICT, $tester->execute(['--all-actions' => true]));
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('CONFLICT_002', $display);
        $this->assertStringContainsString('stay deleted', $display);
    }

    private function tester(ConcurrencyConflict $conflict): CommandTester
    {
        $repository = $this->createMock(ActionLogRepository::class);
        $repository->expects($this->once())->method('purgeBefore')->willThrowException($conflict);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repository);

        return new CommandTester(new PurgeActionLogCommand($registry, KernelActionLog::class));
    }
}
