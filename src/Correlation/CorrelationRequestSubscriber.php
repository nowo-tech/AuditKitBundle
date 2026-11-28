<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation;

use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\Headers;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function str_starts_with;

/**
 * Since 2.2 (with `correlation.provider: true`): one correlation id per main request in {@see CorrelationIdHolder}.
 *
 * - `kernel.request` (main request, first): forgets whatever a previous request left, then starts a context with the
 *   inbound id ({@see InboundCorrelationId}) or a new one. Sub-requests share the main request's id.
 * - `kernel.response` (main request): adds `X-Correlation-Id` to responses whose path starts with one of
 *   `correlation.response_header_paths` (none by default), unless the response already carries one.
 * - `kernel.terminate` (last): clears the holder, so a worker never carries the id into its next request. Log lines
 *   written by other terminate listeners still carry it.
 *
 * Stateless itself; the holder carries the state and is reset-safe.
 *
 * @since 2.2.0
 *
 * @internal registered by the bundle
 */
final readonly class CorrelationRequestSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $responseHeaderPaths path prefixes whose responses carry `X-Correlation-Id`
     */
    public function __construct(
        private CorrelationIdHolder $holder,
        private InboundCorrelationId $inbound,
        private array $responseHeaderPaths = [],
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onRequest', 4096],
            KernelEvents::RESPONSE  => ['onResponse', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->holder->reset();
        $this->holder->begin(new CorrelationContext($this->inbound->from($event->getRequest()) ?? CorrelationId::generate()));
    }

    public function onResponse(ResponseEvent $event): void
    {
        $id = $this->holder->current();
        if (!$event->isMainRequest() || !$id instanceof CorrelationId || $event->getResponse()->headers->has(Headers::CORRELATION_ID)) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach ($this->responseHeaderPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $event->getResponse()->headers->set(Headers::CORRELATION_ID, $id->toString());

                return;
            }
        }
    }

    public function onTerminate(): void
    {
        $this->holder->reset();
    }
}
