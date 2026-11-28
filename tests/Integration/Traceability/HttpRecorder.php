<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Test-only mock response factory: remembers the headers of every outgoing request. */
final class HttpRecorder
{
    /** @var list<list<string>> */
    public array $sent = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        /** @var list<string> $headers */
        $headers      = $options['headers'] ?? [];
        $this->sent[] = $headers;

        return new MockResponse('{}');
    }
}
