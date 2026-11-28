<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\Headers;
use Symfony\Component\HttpFoundation\Request;

use function strcasecmp;

/**
 * Since 2.2: reads the correlation id an incoming request carries, for {@see CorrelationRequestSubscriber}.
 *
 * Headers are tried in the configured order (default `X-Correlation-Id`, then `traceparent`): a `traceparent` gives its
 * trace-id ({@see CorrelationId::tryFromTraceparent()}), any other header its value when it is a valid correlation id
 * ({@see CorrelationId::tryFromString()}). Invalid values are ignored, never echoed.
 *
 * With `$trustedProxiesOnly` (the default) the headers are read only when the request comes from a trusted proxy
 * (`framework.trusted_proxies`): a client calling the application directly cannot choose the id its requests are
 * recorded under, and so cannot mix its rows into another request's trail.
 *
 * @since 2.2.0
 *
 * @internal
 */
final readonly class InboundCorrelationId
{
    /** Default `correlation.inbound_headers`. */
    public const DEFAULT_HEADERS = [Headers::CORRELATION_ID, Headers::TRACEPARENT];

    /**
     * @param list<string> $headers header names, tried in order (`[]` reads none)
     */
    public function __construct(
        private array $headers = self::DEFAULT_HEADERS,
        private bool $trustedProxiesOnly = true,
    ) {
    }

    /** The id the request carries, null when it carries none that is valid or it may not choose one. */
    public function from(Request $request): ?CorrelationId
    {
        if ($this->headers === [] || ($this->trustedProxiesOnly && !$request->isFromTrustedProxy())) {
            return null;
        }

        foreach ($this->headers as $header) {
            $value = $request->headers->get($header);
            $id    = strcasecmp($header, Headers::TRACEPARENT) === 0 ? CorrelationId::tryFromTraceparent($value) : CorrelationId::tryFromString($value);
            if ($id instanceof CorrelationId) {
                return $id;
            }
        }

        return null;
    }
}
