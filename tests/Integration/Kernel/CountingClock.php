<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/** Mock clock that counts {@see now()} calls (one call per audited persist/update). */
final class CountingClock implements ClockInterface
{
    public int $calls = 0;

    private readonly MockClock $clock;

    public function __construct(string $now)
    {
        $this->clock = new MockClock($now);
    }

    public function now(): DateTimeImmutable
    {
        ++$this->calls;

        return $this->clock->now();
    }

    public function modify(string $modifier): void
    {
        $this->clock->modify($modifier);
    }
}
