<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;

/** Test-only handler: remembers the correlation context each message ran under. */
final class TraceHandler
{
    /** @var list<array{0: string|null, 1: string|null}> */
    public array $seen = [];

    public function __construct(private readonly CorrelationIdProviderInterface $provider)
    {
    }

    public function __invoke(): void
    {
        $context      = $this->provider instanceof CorrelationContextProviderInterface ? $this->provider->currentContext() : null;
        $this->seen[] = [$context?->correlationId->toString() ?? $this->provider->current()?->toString(), $context?->causedBy?->toString()];
    }
}
