<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation\Messenger;

use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\Messenger\CorrelationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Since 2.2: carries the correlation id across a message bus (opt-in per bus: add the service
 * `nowo_audit_kit.correlation.messenger_middleware` to the bus `middleware` list).
 *
 * - Dispatch inside a unit of work: an envelope without a {@see CorrelationIdStamp} gets one with the current context
 *   (correlation id, and `causedBy` when the provider tracks causation). A stamp the caller set is kept. Retries keep
 *   the stamp, so a retried message keeps its id.
 * - Dispatch outside any unit of work (a script, a Scheduler tick): no stamp is added, the message has no parent.
 * - Receive (the envelope has a `ReceivedStamp`), with the bundle's {@see CorrelationIdHolder} only:
 *   - a message without a stamp (a Scheduler message, a message dispatched outside any unit of work) runs under a
 *     fresh root id with `causedBy` null, whatever `$childIds` says;
 *   - a stamped message runs under the stamped context, or with `$childIds` under a new id with `causedBy` = the stamped
 *     id, so each handled message is its own unit of work linked to its parent. `causedBy` is set only from a stamp.
 *
 *   The context is ended in a `finally`: the outer one, if any, is current again afterwards, even when the handler
 *   throws.
 * - Receive with a provider of the host's (an alias of `CorrelationIdProviderInterface`): the middleware steps aside
 *   (no context is pushed anywhere); restoring the id is the host provider's job. It still stamps on dispatch.
 *
 * Messages handled synchronously (no `ReceivedStamp`) run in the dispatching unit of work and keep its context. A
 * stamp that does not hold a valid id (a tampered transport message) counts as no stamp.
 *
 * `CorrelationMiddlewareOrderPass` places it in front of `dispatch_after_current_bus`, so messages a handler dispatches
 * with `DispatchAfterCurrentBusStamp` are stamped with the received context, which stays open until they are handled.
 *
 * Stateless; worker-safe through the holder (contract rules 1 and 2).
 *
 * @since 2.2.0
 */
final readonly class CorrelationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorrelationIdProviderInterface $provider,
        private bool $childIds = false,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);
        if (!$envelope->last(ReceivedStamp::class) instanceof StampInterface) {
            $context = $stamp instanceof CorrelationIdStamp ? null : $this->dispatchContext();
            if ($context instanceof CorrelationContext) {
                $envelope = $envelope->with(CorrelationIdStamp::fromContext($context));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        if (!$this->provider instanceof CorrelationIdHolder) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->provider->begin($this->receiveContext($stamp));

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->provider->end();
        }
    }

    /** The context of the dispatching unit of work, null outside any (the message then carries no parent). */
    private function dispatchContext(): ?CorrelationContext
    {
        if ($this->provider instanceof CorrelationContextProviderInterface) {
            return $this->provider->currentContext();
        }

        $id = $this->provider->current();

        return $id instanceof CorrelationId ? new CorrelationContext($id) : null;
    }

    private function receiveContext(?CorrelationIdStamp $stamp): CorrelationContext
    {
        // A stamp restored by a serializer skips the value object's check: validate it again, else it has no parent.
        $id = $stamp instanceof CorrelationIdStamp ? CorrelationId::tryFromString($stamp->correlationId->value) : null;
        if (!$stamp instanceof CorrelationIdStamp || !$id instanceof CorrelationId) {
            return new CorrelationContext(CorrelationId::generate());
        }

        $causedBy = CorrelationId::tryFromString($stamp->causedBy?->value);

        return $this->childIds ? new CorrelationContext(CorrelationId::generate(), $id) : new CorrelationContext($id, $causedBy);
    }
}
