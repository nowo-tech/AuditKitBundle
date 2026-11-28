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
 * - Dispatch: an envelope without a {@see CorrelationIdStamp} gets one with the current context (correlation id and
 *   `causedBy` when the provider tracks causation), or a new id outside any unit of work. A stamp the caller set is
 *   kept. Retries keep the stamp, so a retried message keeps its id.
 * - Receive (the envelope has a `ReceivedStamp`): with the bundle's {@see CorrelationIdHolder} the handler runs under
 *   the stamped context, restored in a `finally` (the outer one, if any, is current again afterwards, even when the
 *   handler throws). With `$childIds` the handler runs under a new id instead, with `causedBy` = the stamped id, so
 *   every handled message is its own unit of work linked to the one that dispatched it. A message without a stamp
 *   gets a new id. With a provider of the host's, receiving is left to the host's own integration.
 *
 * Messages handled synchronously (no `ReceivedStamp`) run in the dispatching unit of work and keep its context.
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
            if (!$stamp instanceof CorrelationIdStamp) {
                $envelope = $envelope->with(CorrelationIdStamp::fromContext($this->dispatchContext()));
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

    private function dispatchContext(): CorrelationContext
    {
        $context = $this->provider instanceof CorrelationContextProviderInterface ? $this->provider->currentContext() : null;

        return $context ?? new CorrelationContext($this->provider->currentOrGenerate());
    }

    private function receiveContext(?CorrelationIdStamp $stamp): CorrelationContext
    {
        if (!$stamp instanceof CorrelationIdStamp) {
            return new CorrelationContext(CorrelationId::generate());
        }

        return $this->childIds ? new CorrelationContext(CorrelationId::generate(), $stamp->correlationId) : $stamp->toContext();
    }
}
