<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuditKitBundle\ActionLog\ActionLogCriteria;
use Nowo\AuditKitBundle\ActionLog\ActionLogReaderInterface;
use Nowo\AuditKitBundle\ActionLog\ActionLogView;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\HttpClient\CorrelationHttpClient;
use Nowo\AuditKitBundle\Timeline\TimelineEvent;
use Nowo\AuditKitBundle\Timeline\TimelineMerger;
use Nowo\AuditKitBundle\Timeline\TimelineSubject;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\Messenger\CorrelationIdStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

use function array_filter;
use function array_map;
use function array_values;
use function count;

/**
 * Correlation id end to end, with the kernel kept alive and no services resetter between units of work (FrankenPHP
 * worker mode, kernel reset off): the HTTP kernel is driven directly, as a worker loop does.
 */
final class TraceabilityKernelTest extends TestCase
{
    private TraceabilityTestKernel $kernel;

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @param array<string, mixed> $correlation
     */
    private function boot(array $correlation = [], bool $hostProvider = false): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-audit-kit-trace-kernel');
        $this->kernel = new TraceabilityTestKernel($correlation, $hostProvider);
        $this->kernel->boot();

        $em = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        $this->kernel->shutdown();
    }

    public function testTwoRequestsInOneWorkerNeverShareAnId(): void
    {
        $first  = $this->handle(Request::create('/api/trace?title=one'));
        $second = $this->handle(Request::create('/api/trace?title=two'));

        $firstId  = $first->headers->get('X-Correlation-Id');
        $secondId = $second->headers->get('X-Correlation-Id');
        $this->assertNotNull($firstId);
        $this->assertNotNull($secondId);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(0, $this->holder()->depth(), 'terminate clears the holder');

        $rows = $this->rows();
        $this->assertSame([$secondId, $firstId], array_map(static fn (ActionLogView $v): ?string => $v->correlationId, $rows));
        $this->assertSame(['title' => ['from' => 'before', 'to' => 'two'], 'password' => ['changed' => true]], $rows[0]->changes);
        $this->assertNull($rows[0]->causedBy);

        $byId = $this->reader()->pageByCriteria(new ActionLogCriteria(correlationId: $firstId))->items;
        $this->assertCount(1, $byId);
        $this->assertSame(['title' => ['from' => 'before', 'to' => 'one'], 'password' => ['changed' => true]], $byId[0]->changes);

        $timeline = $this->kernel->getContainer()->get('test.timeline');
        self::assertInstanceOf(TimelineMerger::class, $timeline);
        $this->assertSame([$byId[0]->id], array_map(static fn (TimelineEvent $e): int => (int) $e->id, $timeline->page(new TimelineSubject('correlation', $firstId))->items));
    }

    public function testOptedInHeadersAreReadOnlyFromATrustedProxy(): void
    {
        $this->boot(['inbound_headers' => ['X-Correlation-Id', 'traceparent']]);
        $spoofed = $this->handle(Request::create('/api/trace', server: ['HTTP_X_CORRELATION_ID' => 'victim-request-id']));
        $this->assertNotSame('victim-request-id', $spoofed->headers->get('X-Correlation-Id'));

        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $proxied = $this->handle(Request::create('/api/trace', server: ['HTTP_X_CORRELATION_ID' => 'edge-id-0001']));
        $this->assertSame('edge-id-0001', $proxied->headers->get('X-Correlation-Id'));
        $this->assertSame('edge-id-0001', $this->rows()[0]->correlationId);
    }

    public function testResponsesOutsideTheConfiguredPathsCarryNoHeaderButRowsKeepTheId(): void
    {
        $response = $this->handle(Request::create('/page'));

        $this->assertNull($response->headers->get('X-Correlation-Id'));
        $this->assertNotNull($this->rows()[0]->correlationId);
    }

    public function testEveryConsoleRunHasItsOwnId(): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $this->assertSame(0, $application->run(new ArrayInput(['command' => TraceCommand::NAME]), new NullOutput()));
        $this->assertSame(0, $application->run(new ArrayInput(['command' => TraceCommand::NAME]), new NullOutput()));

        $ids = array_map(static fn (ActionLogView $v): ?string => $v->correlationId, $this->rows());
        $this->assertCount(2, $ids);
        $this->assertNotNull($ids[0]);
        $this->assertNotNull($ids[1]);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame(0, $this->holder()->depth());
    }

    public function testMessengerStampsOnDispatchAndRestoresOnReceive(): void
    {
        $bus = $this->kernel->getContainer()->get('test.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $handler = $this->kernel->getContainer()->get(TraceHandler::class);
        self::assertInstanceOf(TraceHandler::class, $handler);
        $holder = $this->holder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('request-7')));

        $sent = $bus->dispatch(new TraceMessage());
        $bus->dispatch(new Envelope(new TraceMessage(), [new CorrelationIdStamp(CorrelationId::fromString('queued-3')), new ReceivedStamp('async')]));

        $this->assertSame('request-7', $sent->last(CorrelationIdStamp::class)?->correlationId->toString());
        $this->assertSame([['request-7', null], ['queued-3', null]], $handler->seen);
        $this->assertSame('request-7', $holder->current()?->toString());
        $holder->reset();
    }

    public function testTheSameIdIsOnTheResponseTheOutgoingRequestAndTheMailWithAConfiguredHeaderName(): void
    {
        $this->boot(['header_name' => 'X-Request-Id']);

        $response = $this->handle(Request::create('/api/notify'));

        $id = $response->headers->get('X-Request-Id');
        $this->assertNotNull($id);
        $this->assertFalse($response->headers->has('X-Correlation-Id'));
        $this->assertContains('X-Request-Id: ' . $id, $this->service(HttpRecorder::class)->sent[0]);
        $this->assertSame([$id], $this->service(MailRecorder::class)->headers);
    }

    /**
     * @param array<string, mixed> $correlation
     */
    #[DataProvider('inboundOff')]
    public function testInboundIdsAreNeverAdoptedByDefaultOrWithAnEmptyList(array $correlation): void
    {
        $this->boot($correlation);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $response = $this->handle(Request::create('/api/trace', server: ['HTTP_X_CORRELATION_ID' => 'edge-id-0001', 'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']));

        $id = $response->headers->get('X-Correlation-Id');
        $this->assertNotNull($id);
        $this->assertNotSame('edge-id-0001', $id);
        $this->assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $id);
        $this->assertSame($id, $this->rows()[0]->correlationId);
    }

    /**
     * With a host provider the bundle registers no holder and no listener: rows, outgoing requests and e-mails read the
     * host's id, the middleware only stamps, and nothing of the bundle survives a request or a message.
     */
    public function testWithAHostProviderTheBundleOnlyReadsItAndKeepsNoState(): void
    {
        $this->boot(hostProvider: true);
        $host                                           = $this->service(HostCorrelationProvider::class);
        $this->service(MailRecorder::class)->headerName = 'X-Correlation-Id';

        $first  = $this->handle(Request::create('/api/trace'));
        $second = $this->handle(Request::create('/api/notify'));
        $this->handle(Request::create('/api/trace'));

        $this->assertFalse($first->headers->has('X-Correlation-Id'), 'no bundle request subscriber');
        $this->assertFalse($second->headers->has('X-Correlation-Id'));
        $this->assertSame(['host-3', 'host-1'], array_map(static fn (ActionLogView $v): ?string => $v->correlationId, $this->rows()));
        $this->assertContains('X-Correlation-Id: host-2', $this->service(HttpRecorder::class)->sent[0]);
        $this->assertSame(['host-2'], $this->service(MailRecorder::class)->headers);
        $this->assertNull($host->current(), 'the host provider reset itself at terminate');
        $this->assertFalse($this->kernel->getContainer()->has('test.correlation_holder'));

        $bus = $this->service('test.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $handler = $this->service(TraceHandler::class);
        $host->onRequest();
        $sent = $bus->dispatch(new TraceMessage());
        $bus->dispatch(new Envelope(new TraceMessage(), [new CorrelationIdStamp(CorrelationId::fromString('queued-9')), new ReceivedStamp('async')]));

        $this->assertSame('host-4', $sent->last(CorrelationIdStamp::class)?->correlationId->toString());
        $this->assertSame([['host-4', null], ['host-4', null]], $handler->seen, 'receiving steps aside: the host restores ids itself');
        $this->assertSame('host-4', $host->current()?->toString());
        $host->reset();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function inboundOff(): iterable
    {
        yield 'default' => [[]];
        yield 'explicit []' => [['inbound_headers' => []]];
    }

    /**
     * A handler of a received message dispatches with DispatchAfterCurrentBusStamp: the follow-up is stamped with the
     * received context and handled while it is still current (the order pass puts the middleware in front of
     * dispatch_after_current_bus).
     */
    #[DataProvider('childIdModes')]
    public function testMessagesDispatchedAfterTheCurrentBusKeepTheReceivedContext(bool $childIds): void
    {
        $this->boot(['messenger_child_ids' => $childIds]);
        $bus = $this->service('test.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $bus->dispatch(new Envelope(new AfterBusMessage(), [new CorrelationIdStamp(CorrelationId::fromString('queued-5'), CorrelationId::fromString('req-1')), new ReceivedStamp('async')]));

        $followUps = array_values(array_filter($this->service(EnvelopeRecorder::class)->envelopes, static fn (Envelope $e): bool => $e->getMessage() instanceof TraceMessage));
        $this->assertCount(1, $followUps);
        $stamp = $followUps[0]->last(CorrelationIdStamp::class);
        self::assertInstanceOf(CorrelationIdStamp::class, $stamp);
        $seen = $this->service(TraceHandler::class)->seen;
        $this->assertCount(1, $seen);
        $causedBy = $stamp->causedBy?->toString();

        if ($childIds) {
            $this->assertNotSame('queued-5', $stamp->correlationId->toString());
            $this->assertSame('queued-5', $causedBy);
        } else {
            $this->assertSame('queued-5', $stamp->correlationId->toString());
            $this->assertSame('req-1', $causedBy);
        }
        $this->assertSame([$stamp->correlationId->toString(), $causedBy], $seen[0]);
        $this->assertSame(0, $this->holder()->depth());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function childIdModes(): iterable
    {
        yield 'chain' => [false];
        yield 'child ids' => [true];
    }

    public function testHttpClientIsDecorated(): void
    {
        $this->assertInstanceOf(CorrelationHttpClient::class, $this->kernel->getContainer()->get('test.http_client'));
    }

    private function handle(Request $request): Response
    {
        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);
        $response = $http->handle($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $http->terminate($request, $response);

        return $response;
    }

    /**
     * @return list<ActionLogView> newest first
     */
    private function rows(): array
    {
        $rows = $this->reader()->pageByCriteria(new ActionLogCriteria())->items;
        $this->assertGreaterThan(0, count($rows));

        return $rows;
    }

    private function reader(): ActionLogReaderInterface
    {
        $reader = $this->kernel->getContainer()->get('test.action_log_reader');
        self::assertInstanceOf(ActionLogReaderInterface::class, $reader);

        return $reader;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    private function service(string $id): object
    {
        return $this->kernel->getContainer()->get($id);
    }

    private function holder(): CorrelationIdHolder
    {
        $holder = $this->kernel->getContainer()->get('test.correlation_holder');
        self::assertInstanceOf(CorrelationIdHolder::class, $holder);

        return $holder;
    }
}
