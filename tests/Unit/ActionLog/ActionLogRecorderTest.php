<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use Doctrine\Persistence\ManagerRegistry;
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
}
