<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation\HttpClient;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\Headers;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

use function explode;
use function is_array;
use function is_int;
use function is_string;
use function strcasecmp;
use function trim;

/**
 * Since 2.2 (opt-in, `correlation.http_client: true`): decorates `http_client` so every outgoing request made inside a
 * unit of work carries `X-Correlation-Id: <current id>` (or the configured `correlation.header_name`), unless the caller
 * set that header (compared case-insensitively). Outside any unit of work
 * nothing is added (no id is generated for it).
 *
 * Only the `http_client` service is decorated; a scoped client built with `withOptions()` keeps the decoration.
 * Stateless: the provider is asked on every request.
 *
 * @since 2.2.0
 */
final class CorrelationHttpClient implements HttpClientInterface, ResetInterface
{
    use DecoratorTrait;

    /**
     * @param string $headerName the header to send (`correlation.header_name`, default `X-Correlation-Id`)
     */
    public function __construct(
        HttpClientInterface $client,
        private readonly CorrelationIdProviderInterface $provider,
        private readonly string $headerName = Headers::CORRELATION_ID,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $id = $this->provider->current();
        if ($id instanceof CorrelationId && !self::hasHeader($options['headers'] ?? [], $this->headerName)) {
            $headers                    = is_array($options['headers'] ?? null) ? $options['headers'] : [];
            $headers[$this->headerName] = $id->toString();
            $options['headers']         = $headers;
        }

        return $this->client->request($method, $url, $options);
    }

    /** Whether `$headers` (name => value(s), or a list of "Name: value" lines) holds `$name`, case-insensitively. */
    public static function hasHeader(mixed $headers, string $name): bool
    {
        if (!is_array($headers)) {
            return false;
        }

        foreach ($headers as $key => $value) {
            $header = is_int($key) && is_string($value) ? trim(explode(':', $value, 2)[0]) : (string) $key;
            if (strcasecmp($header, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
