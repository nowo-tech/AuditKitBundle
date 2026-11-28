<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\HttpClient\CorrelationHttpClient;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CorrelationHttpClientTest extends TestCase
{
    /** @var list<list<string>> */
    private array $sent = [];

    public function testOutgoingRequestsCarryTheCurrentId(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-42')));
        $client = new CorrelationHttpClient($this->mock(), $holder);

        $client->request('GET', 'https://example.test/a', ['headers' => ['Accept' => 'application/json']]);
        $client->withOptions(['base_uri' => 'https://example.test'])->request('GET', '/b');

        $this->assertContains('X-Correlation-Id: req-42', $this->sent[0]);
        $this->assertContains('Accept: application/json', $this->sent[0]);
        $this->assertContains('X-Correlation-Id: req-42', $this->sent[1]);
    }

    public function testACallerHeaderWinsAndNoIdMeansNoHeader(): void
    {
        $holder = new CorrelationIdHolder();
        $client = new CorrelationHttpClient($this->mock(), $holder);

        $client->request('GET', 'https://example.test/none');
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-42')));
        $client->request('GET', 'https://example.test/assoc', ['headers' => ['x-correlation-id' => 'caller']]);
        $client->request('GET', 'https://example.test/list', ['headers' => ['X-CORRELATION-ID: caller2']]);
        $client->reset();

        $this->assertSame([[], ['x-correlation-id: caller'], ['x-correlation-id: caller2']], $this->correlationHeaders());
        $this->assertFalse(CorrelationHttpClient::hasHeader('X-Correlation-Id: x', 'X-Correlation-Id'));
    }

    public function testAConfiguredHeaderNameIsSentAndChecked(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-7')));
        $client = new CorrelationHttpClient($this->mock(), $holder, 'X-Request-Id');

        $client->request('GET', 'https://example.test/a');
        $client->request('GET', 'https://example.test/b', ['headers' => ['x-request-id' => 'caller']]);
        $client->request('GET', 'https://example.test/c', ['headers' => ['X-Correlation-Id' => 'other']]);

        $this->assertContains('X-Request-Id: req-7', $this->sent[0]);
        $this->assertNotContains('X-Request-Id: req-7', $this->sent[1]);
        $this->assertContains('x-request-id: caller', $this->sent[1]);
        $this->assertContains('X-Request-Id: req-7', $this->sent[2], 'only the configured name counts as caller-set');
    }

    private function mock(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var list<string> $headers */
            $headers      = $options['headers'] ?? [];
            $this->sent[] = $headers;

            return new MockResponse('{}');
        });
    }

    /**
     * @return list<list<string>>
     */
    private function correlationHeaders(): array
    {
        $out = [];
        foreach ($this->sent as $headers) {
            $out[] = array_values(array_map(strtolower(...), array_filter($headers, static fn (string $h): bool => stripos($h, 'x-correlation-id') === 0)));
        }

        return $out;
    }
}
