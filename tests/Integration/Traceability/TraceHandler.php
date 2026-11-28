<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;

/** Test-only handler: remembers the correlation context each message ran under. */
final class TraceHandler
{
    /** @var list<array{0: string|null, 1: string|null}> */
    public array $seen = [];

    public function __construct(private readonly CorrelationIdHolder $holder)
    {
    }

    public function __invoke(): void
    {
        $context      = $this->holder->currentContext();
        $this->seen[] = [$context?->correlationId->toString(), $context?->causedBy?->toString()];
    }
}
