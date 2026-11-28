<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InboundCorrelationIdTest extends TestCase
{
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testHeadersFromAnUntrustedClientAreIgnored(): void
    {
        $request = $this->request(['HTTP_X_CORRELATION_ID' => 'client-chosen-id']);

        $this->assertNull((new InboundCorrelationId())->from($request));
    }

    public function testATrustedProxyIdIsAdoptedInHeaderOrder(): void
    {
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $both = $this->request(['HTTP_X_CORRELATION_ID' => 'edge-id-123', 'HTTP_TRACEPARENT' => self::TRACEPARENT]);
        $this->assertSame('edge-id-123', (new InboundCorrelationId())->from($both)?->toString());

        $traceOnly = $this->request(['HTTP_X_CORRELATION_ID' => 'bad id with spaces', 'HTTP_TRACEPARENT' => self::TRACEPARENT]);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', (new InboundCorrelationId())->from($traceOnly)?->toString());

        $invalid = $this->request(['HTTP_TRACEPARENT' => 'ff-0-0-0']);
        $this->assertNull((new InboundCorrelationId())->from($invalid));
    }

    public function testNoHeadersConfiguredReadsNothing(): void
    {
        $this->assertNull((new InboundCorrelationId([], false))->from($this->request(['HTTP_X_CORRELATION_ID' => 'abc'])));
    }

    public function testAnyClientWhenTrustedProxiesOnlyIsOff(): void
    {
        $this->assertSame('abc', (new InboundCorrelationId(['X-Request-Id'], false))->from($this->request(['HTTP_X_REQUEST_ID' => 'abc']))?->toString());
    }

    /**
     * @param array<string, string> $server
     */
    private function request(array $server): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1'] + $server);
    }
}
