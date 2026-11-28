<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogCursor;
use Nowo\AuditKitBundle\ActionLog\ActionLogPage;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;
use Nowo\AuditKitBundle\Timeline\ActionLogTimelineSource;
use Nowo\AuditKitBundle\Timeline\TimelineCriteria;
use Nowo\AuditKitBundle\Timeline\TimelineCursor;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use PHPUnit\Framework\TestCase;

use const PHP_INT_MAX;

final class ActionLogTimelineSourceTest extends TestCase
{
    /** @var list<array{0: ActionLogCriteria, 1: ActionLogCursor|null}> */
    private array $calls = [];

    public function testSubjectsBecomeReaderCriteriaAndViewsBecomeEvents(): void
    {
        $source = new ActionLogTimelineSource($this->reader());
        $since  = new DateTimeImmutable('2026-01-01');

        $events = $source->page(new TimelineSubject('user', 'u1'), new TimelineCriteria($since, null, ['item.viewed']), null, 5);
        $source->page(new TimelineSubject('scope', 'project-1'), new TimelineCriteria(correlationId: 'c1'), null, 5000);
        $source->page(new TimelineSubject('correlation', 'c1'), new TimelineCriteria(), null, 5);

        [$criteria, $cursor] = $this->calls[0];
        $this->assertSame('u1', $criteria->involving);
        $this->assertSame(['item.viewed'], $criteria->actions);
        $this->assertSame($since, $criteria->from);
        $this->assertSame(5, $criteria->limit);
        $this->assertNull($cursor);
        $this->assertSame(['project-1'], $this->calls[1][0]->scopes);
        $this->assertSame('c1', $this->calls[1][0]->correlationId);
        $this->assertSame(ActionLogReaderInterface::MAX_LIMIT, $this->calls[1][0]->limit);
        $this->assertSame('c1', $this->calls[2][0]->correlationId);

        $this->assertCount(1, $events);
        $this->assertSame('action_log', $events[0]->source);
        $this->assertSame('12', $events[0]->id);
        $this->assertSame('item.viewed', $events[0]->kind);
        $this->assertSame('a1', $events[0]->actorId);
        $this->assertSame('corr-1', $events[0]->correlationId);
        $this->assertSame(['title' => ['from' => 'a', 'to' => 'b']], $events[0]->changes);
        $this->assertSame(['subject_id' => 's1', 'scope' => 'p1', 'context' => ['k' => 'v']], $events[0]->payload);
    }

    public function testUnknownSubjectOrAnotherCorrelationGivesNothing(): void
    {
        $source = new ActionLogTimelineSource($this->reader());

        $this->assertSame([], $source->page(new TimelineSubject('task', '1'), new TimelineCriteria(), null, 5));
        $this->assertSame([], $source->page(new TimelineSubject('correlation', 'c1'), new TimelineCriteria(correlationId: 'c2'), null, 5));
        $this->assertSame([], $this->calls);
    }

    public function testCursorBoundsBecomeActionLogCursors(): void
    {
        $source = new ActionLogTimelineSource($this->reader(), 'log');
        $at     = new DateTimeImmutable('2026-01-01 10:00');
        $user   = new TimelineSubject('user', 'u1');

        $source->page($user, new TimelineCriteria(), new TimelineCursor($at, 'zzz', 'x'), 5);
        $source->page($user, new TimelineCriteria(), new TimelineCursor($at, 'aaa', 'x'), 5);
        $source->page($user, new TimelineCriteria(), new TimelineCursor($at, 'log', '42'), 5);
        $source->page($user, new TimelineCriteria(), new TimelineCursor($at, 'log', 'not-an-id'), 5);

        $this->assertEquals(new ActionLogCursor($at, 0), $this->calls[0][1]);
        $this->assertEquals(new ActionLogCursor($at, PHP_INT_MAX), $this->calls[1][1]);
        $this->assertEquals(new ActionLogCursor($at, 42), $this->calls[2][1]);
        $this->assertEquals(new ActionLogCursor($at, 0), $this->calls[3][1]);
    }

    public function testTheCorrelationSubjectReChecksEveryRow(): void
    {
        $reader = $this->createStub(ActionLogReaderInterface::class);
        $reader->method('pageByCriteria')->willReturn(new ActionLogPage([
            new ActionLogView(1, 'a', null, null, [], 'tenant-a', null, new DateTimeImmutable('2026-01-01'), 'c1'),
            new ActionLogView(2, 'a', null, null, [], 'tenant-b', null, new DateTimeImmutable('2026-01-01'), 'other'),
            new ActionLogView(3, 'a', null, null, [], 'tenant-b', null, new DateTimeImmutable('2026-01-01')),
        ]));
        $source = new ActionLogTimelineSource($reader);

        $this->assertSame(['1'], array_map(static fn (TimelineEvent $e): string => $e->id, $source->page(new TimelineSubject('correlation', 'c1'), new TimelineCriteria(), null, 5)));
        $this->assertSame(['1'], array_map(static fn (TimelineEvent $e): string => $e->id, $source->page(new TimelineSubject('scope', 'tenant-a'), new TimelineCriteria(correlationId: 'c1'), null, 5)));
        $this->assertCount(3, $source->page(new TimelineSubject('scope', 'tenant-a'), new TimelineCriteria(), null, 5));
    }

    public function testAForeignCursorIsComparedInTheServerTimezoneAtWholeSeconds(): void
    {
        $timezone = date_default_timezone_get();
        // @phpstan-ignore frankenphp.worker.noDateDefaultTimezoneSet (test only, restored in finally)
        date_default_timezone_set('Europe/Madrid');

        try {
            $source  = new ActionLogTimelineSource($this->reader());
            $user    = new TimelineSubject('user', 'u1');
            $foreign = new DateTimeImmutable('2026-01-01 12:00:00.500000', new DateTimeZone('UTC'));

            $source->page($user, new TimelineCriteria(), new TimelineCursor($foreign, 'comments', 'c-9'), 5);
            $source->page($user, new TimelineCriteria(), new TimelineCursor($foreign, 'action_log', '7'), 5);
            $source->page($user, new TimelineCriteria(), new TimelineCursor(new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')), 'comments', 'c-9'), 5);

            $madrid = new DateTimeImmutable('2026-01-01 13:00:00', new DateTimeZone('Europe/Madrid'));
            foreach ([[0, PHP_INT_MAX], [1, PHP_INT_MAX], [2, 0]] as [$call, $id]) {
                $cursor = $this->calls[$call][1];
                $this->assertNotNull($cursor);
                $this->assertSame('2026-01-01 13:00:00.000000 Europe/Madrid', $cursor->createdAt->format('Y-m-d H:i:s.u e'));
                $this->assertEquals($madrid, $cursor->createdAt);
                $this->assertSame($id, $cursor->id);
            }
        } finally {
            // @phpstan-ignore frankenphp.worker.noDateDefaultTimezoneSet (test only)
            date_default_timezone_set($timezone);
        }
    }

    private function reader(): ActionLogReaderInterface
    {
        $reader = $this->createStub(ActionLogReaderInterface::class);
        $reader->method('pageByCriteria')->willReturnCallback(function (ActionLogCriteria $criteria, ?ActionLogCursor $after): ActionLogPage {
            $this->calls[] = [$criteria, $after];

            return new ActionLogPage([new ActionLogView(12, 'item.viewed', 'a1', 's1', ['k' => 'v'], 'p1', null, new DateTimeImmutable('2026-01-01'), 'corr-1', null, ['title' => ['from' => 'a', 'to' => 'b']])]);
        });

        return $reader;
    }
}
