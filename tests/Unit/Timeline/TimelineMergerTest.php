<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Timeline;

use DateTimeImmutable;
use Nowo\AuditKitBundle\Timeline\TimelineCriteria;
use Nowo\AuditKitBundle\Timeline\TimelineCursor;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\AuditKitBundle\Timeline\TimelineSourceInterface;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function count;

final class TimelineMergerTest extends TestCase
{
    public function testMergesNewestFirstWithTiesBySourceThenSourceOrder(): void
    {
        $merger = new TimelineMerger([
            $this->source('tasks', [['10:00', '3'], ['10:00', '2'], ['09:00', '1']]),
            $this->source('audit', [['10:00', '9'], ['11:00', '8']]),
        ]);

        $page = $merger->page(new TimelineSubject('user', 'u1'));

        $this->assertSame(['audit#8', 'audit#9', 'tasks#3', 'tasks#2', 'tasks#1'], $this->labels($page->items));
        $this->assertNull($page->next);
    }

    public function testPagesAreStableAcrossSourcesAndTies(): void
    {
        $merger = new TimelineMerger([
            $this->source('tasks', [['10:00', '3'], ['10:00', '2'], ['10:00', '1'], ['08:00', '0']]),
            $this->source('audit', [['10:00', '9'], ['10:00', '8'], ['09:00', '7']]),
            $this->source('wiki', [['10:00', 'b'], ['10:00', 'a']]),
        ]);
        $all = $this->labels($merger->page(new TimelineSubject('user', 'u1'), limit: 100)->items);

        $paged = [];
        $after = null;
        do {
            $page  = $merger->page(new TimelineSubject('user', 'u1'), new TimelineCriteria(), $after, 2);
            $paged = [...$paged, ...$this->labels($page->items)];
            $after = $page->next instanceof TimelineCursor ? TimelineCursor::fromToken($page->next->toToken()) : null;
        } while ($after instanceof TimelineCursor);

        $this->assertSame(['audit#9', 'audit#8', 'tasks#3', 'tasks#2', 'tasks#1', 'wiki#b', 'wiki#a', 'audit#7', 'tasks#0'], $all);
        $this->assertSame($all, $paged);
    }

    public function testEmptyKindsMatchNothingAndTheLimitIsBounded(): void
    {
        $merger = new TimelineMerger([$this->source('audit', [['10:00', '1']])]);

        $this->assertSame([], $merger->page(new TimelineSubject('user', 'u1'), new TimelineCriteria(kinds: []))->items);
        $this->assertCount(1, $merger->page(new TimelineSubject('user', 'u1'), limit: 0)->items);
        $this->assertSame([], (new TimelineMerger())->page(new TimelineSubject('user', 'u1'), limit: 100000)->items);
    }

    /**
     * @param list<array{0: string, 1: string}> $rows time and id, in the source's own order (newest first)
     */
    private function source(string $name, array $rows): TimelineSourceInterface
    {
        return new class($name, $rows) implements TimelineSourceInterface {
            /**
             * @param list<array{0: string, 1: string}> $rows
             */
            public function __construct(private readonly string $name, private readonly array $rows)
            {
            }

            public function page(TimelineSubject $subject, TimelineCriteria $criteria, ?TimelineCursor $after, int $limit): array
            {
                $events = [];
                foreach ($this->rows as $position => [$time, $id]) {
                    $at = new DateTimeImmutable('2026-01-01 ' . $time);
                    if ($after instanceof TimelineCursor && !$this->isAfter($after, $at, $position)) {
                        continue;
                    }
                    $events[] = new TimelineEvent($this->name, $id, $at, 'k');
                }

                return array_slice($events, 0, $limit);
            }

            private function isAfter(TimelineCursor $after, DateTimeImmutable $at, int $position): bool
            {
                if ($at < $after->occurredAt) {
                    return true;
                }
                if ($at > $after->occurredAt) {
                    return false;
                }

                return match ($after->boundFor($this->name)) {
                    TimelineCursor::OLDER        => false,
                    TimelineCursor::SAME_INSTANT => true,
                    default                      => $position > $this->positionOf($after->id),
                };
            }

            private function positionOf(string $id): int
            {
                foreach ($this->rows as $position => [, $rowId]) {
                    if ($rowId === $id) {
                        return $position;
                    }
                }

                return count($this->rows);
            }
        };
    }

    /**
     * @param list<TimelineEvent> $events
     *
     * @return list<string>
     */
    private function labels(array $events): array
    {
        return array_map(static fn (TimelineEvent $e): string => $e->source . '#' . $e->id, $events);
    }
}
