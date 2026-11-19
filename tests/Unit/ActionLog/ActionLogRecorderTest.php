<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class ActionLogRecorderTest extends TestCase
{
    public function testThrowsWhenNoOrmManagerHandlesTheEntity(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager manages the action log class');

        (new ActionLogRecorder($registry, new RequestStack(), KernelActionLog::class))->record('item.viewed');
    }

    /** Since 1.17: a closed manager the registry does not list is not replaced; persisting into it is left to the ORM. */
    public function testAClosedManagerTheRegistryDoesNotListIsUsedAsItIs(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->once())->method('persist')->with($this->isInstanceOf(KernelActionLog::class));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['other' => 'doctrine.orm.other_entity_manager']);
        $registry->method('getManager')->with('other')->willReturn($this->createStub(EntityManagerInterface::class));
        $registry->expects($this->never())->method('resetManager');

        $entry = (new ActionLogRecorder($registry, new RequestStack(), KernelActionLog::class, captureIp: false))->record('item.failed');

        $this->assertSame('item.failed', $entry->getAction());
    }

    public function testRejectsAnUnknownFlushMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action log flush mode "everything"');

        new ActionLogRecorder($this->createStub(ManagerRegistry::class), new RequestStack(), KernelActionLog::class, flushMode: 'everything');
    }
}
