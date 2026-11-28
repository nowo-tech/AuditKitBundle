<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Test-only provider of a host: one sequential id per main request, cleared at terminate (its own reset). */
final class HostCorrelationProvider implements CorrelationIdProviderInterface, ResetInterface
{
    private ?CorrelationId $current = null;

    private int $sequence = 0;

    public function onRequest(): void
    {
        $this->current = CorrelationId::fromString('host-' . ++$this->sequence);
    }

    public function current(): ?CorrelationId
    {
        return $this->current;
    }

    public function currentOrGenerate(): CorrelationId
    {
        return $this->current ?? CorrelationId::generate();
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
