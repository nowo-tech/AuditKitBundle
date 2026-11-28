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
 * Off by default: `correlation.inbound_headers` is `[]`, so every request gets a new id and an inbound value never becomes
 * the correlation id (keep it elsewhere, for example as an upstream id in the host's HTTP log).
 *
 * When a host opts in, headers are tried in the configured order (e.g. `X-Correlation-Id`, then `traceparent`): a
 * `traceparent` gives its trace-id ({@see CorrelationId::tryFromTraceparent()}), any other header its value when it is a
 * valid correlation id ({@see CorrelationId::tryFromString()}). Invalid values are ignored, never echoed. With
 * `$trustedProxiesOnly` (the default) the headers are read only when the request comes from a trusted proxy
 * (`framework.trusted_proxies`). That proves which machine opened the connection, not who wrote the header: the edge
 * proxy MUST strip or overwrite these headers on requests from the internet, otherwise clients can still choose the id
 * their requests are recorded under.
 *
 * @since 2.2.0
 *
 * @internal
 */
final readonly class InboundCorrelationId
{
    /** Default `correlation.inbound_headers`: none (inbound adoption is opt-in). */
    public const DEFAULT_HEADERS = [];

    /** The headers a host usually opts in to (its edge proxy must strip or overwrite them). */
    public const SUPPORTED_HEADERS = [Headers::CORRELATION_ID, Headers::TRACEPARENT];

    /**
     * @param list<string> $headers header names, tried in order (`[]`, the default, reads none)
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
