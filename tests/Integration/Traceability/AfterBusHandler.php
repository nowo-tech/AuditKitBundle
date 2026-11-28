<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/** Dispatches a follow-up message after the current bus, as a handler raising domain events does. */
final readonly class AfterBusHandler
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(): void
    {
        $this->bus->dispatch(new TraceMessage(), [new DispatchAfterCurrentBusStamp()]);
    }
}
