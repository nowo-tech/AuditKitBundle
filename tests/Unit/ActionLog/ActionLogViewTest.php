<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use DateTimeImmutable;
use Nowo\AuditKitBundle\ActionLog\AbstractIdActionLog;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogPage;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ActionLogViewTest extends TestCase
{
    public function testFromEntryCopiesIdsAndScalarsOnly(): void
    {
        $entry = new class extends AbstractIdActionLog {
        };
        $entry->setAction('invoice.sent')
            ->setActorId('alice')
            ->setSubjectId(7)
            ->setContext(['invoice' => 'inv-1'])
            ->setScope('tenant-a')
            ->setIpAddress('192.0.2.1');
        (new ReflectionProperty(AbstractIdActionLog::class, 'id'))->setValue($entry, 12);

        $view = ActionLogView::fromEntry($entry);

        $this->assertSame(12, $view->id);
        $this->assertSame('invoice.sent', $view->action);
        $this->assertSame('alice', $view->actorId);
        $this->assertSame('7', $view->subjectId);
        $this->assertSame(['invoice' => 'inv-1'], $view->context);
        $this->assertSame('tenant-a', $view->scope);
        $this->assertSame('192.0.2.1', $view->ipAddress);
        $this->assertSame($entry->getCreatedAt(), $view->createdAt);

        $cursor = $view->cursor();
        $this->assertSame(12, $cursor->id);
        $this->assertSame($entry->getCreatedAt(), $cursor->createdAt);
    }

    public function testPageReportsWhetherANextPageExists(): void
    {
        $this->assertFalse((new ActionLogPage([]))->hasMore());
        $this->assertTrue((new ActionLogPage([], new ActionLogCursor(new DateTimeImmutable(), 1)))->hasMore());
    }
}
