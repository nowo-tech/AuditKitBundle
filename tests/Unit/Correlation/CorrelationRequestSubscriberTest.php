<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\CorrelationRequestSubscriber;
use Nowo\AuditKitBundle\Correlation\InboundCorrelationId;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorrelationRequestSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $this->assertSame([
            KernelEvents::REQUEST   => ['onRequest', 4096],
            KernelEvents::RESPONSE  => ['onResponse', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ], CorrelationRequestSubscriber::getSubscribedEvents());
    }

    public function testAMainRequestReplacesWhateverWasLeftAndSubRequestsShareIt(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('stale')));
        $subscriber = new CorrelationRequestSubscriber($holder, new InboundCorrelationId());
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        $id = $holder->current();
        $this->assertNotNull($id);
        $this->assertNotSame('stale', $id->toString());
        $this->assertSame(1, $holder->depth());

        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/fragment'), HttpKernelInterface::SUB_REQUEST));
        $this->assertSame($id, $holder->current());

        $subscriber->onTerminate();
        $this->assertNull($holder->current());
    }

    public function testResponseHeaderOnlyUnderTheConfiguredPaths(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-1')));
        $subscriber = new CorrelationRequestSubscriber($holder, new InboundCorrelationId(), ['/api', '/mcp']);

        $this->assertSame('req-1', $this->respond($subscriber, '/api/tasks')->headers->get('X-Correlation-Id'));
        $this->assertNull($this->respond($subscriber, '/admin')->headers->get('X-Correlation-Id'));
        $this->assertNull($this->respond($subscriber, '/api/sub', HttpKernelInterface::SUB_REQUEST)->headers->get('X-Correlation-Id'));

        $own = new Response();
        $own->headers->set('X-Correlation-Id', 'mine');
        $this->assertSame('mine', $this->respond($subscriber, '/api', HttpKernelInterface::MAIN_REQUEST, $own)->headers->get('X-Correlation-Id'));

        $holder->reset();
        $this->assertNull($this->respond($subscriber, '/api')->headers->get('X-Correlation-Id'));
    }

    public function testAConfiguredHeaderName(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-2')));
        $subscriber = new CorrelationRequestSubscriber($holder, new InboundCorrelationId(), ['/api'], 'X-Request-Id');

        $response = $this->respond($subscriber, '/api/x');
        $this->assertSame('req-2', $response->headers->get('X-Request-Id'));
        $this->assertFalse($response->headers->has('X-Correlation-Id'));

        $own = new Response();
        $own->headers->set('x-request-id', 'mine');
        $this->assertSame('mine', $this->respond($subscriber, '/api', HttpKernelInterface::MAIN_REQUEST, $own)->headers->get('X-Request-Id'));
    }

    private function respond(CorrelationRequestSubscriber $subscriber, string $path, int $type = HttpKernelInterface::MAIN_REQUEST, Response $response = new Response()): Response
    {
        $subscriber->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create($path), $type, $response));

        return $response;
    }
}
