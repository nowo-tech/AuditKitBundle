<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationConsoleSubscriber;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;

final class CorrelationConsoleSubscriberTest extends TestCase
{
    public function testEveryCommandRunGetsItsOwnIdAndNestedRunsGiveTheOuterOneBack(): void
    {
        $this->assertSame([
            ConsoleEvents::COMMAND   => ['onCommand', 4096],
            ConsoleEvents::TERMINATE => ['onTerminate', -4096],
        ], CorrelationConsoleSubscriber::getSubscribedEvents());

        $holder     = new CorrelationIdHolder();
        $subscriber = new CorrelationConsoleSubscriber($holder);

        $subscriber->onCommand();
        $outer = $holder->current();
        $subscriber->onCommand();
        $inner = $holder->current();
        $this->assertNotNull($outer);
        $this->assertNotNull($inner);
        $this->assertFalse($outer->equals($inner));

        $subscriber->onTerminate();
        $this->assertSame($outer, $holder->current());
        $subscriber->onTerminate();
        $this->assertNull($holder->current());
    }
}
