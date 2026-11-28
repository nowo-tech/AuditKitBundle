<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\Messenger\CorrelationMiddleware;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\Messenger\CorrelationIdStamp;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function strlen;

final class CorrelationMiddlewareTest extends TestCase
{
    /** @var list<array{0: string|null, 1: string|null}> correlation id and caused_by seen by the handler */
    private array $seen = [];

    public function testDispatchStampsTheCurrentContext(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('request-1'), CorrelationId::fromString('parent-0')));

        $envelope = $this->bus($holder)->dispatch(new stdClass());
        $stamp    = $envelope->last(CorrelationIdStamp::class);

        $this->assertInstanceOf(CorrelationIdStamp::class, $stamp);
        $this->assertSame('request-1', $stamp->correlationId->toString());
        $this->assertSame('parent-0', $stamp->causedBy?->toString());
        $this->assertSame([['request-1', 'parent-0']], $this->seen, 'a synchronously handled message keeps the dispatching context');
    }

    public function testDispatchOutsideAUnitOfWorkAddsNoParentAndKeepsACallerStamp(): void
    {
        $this->assertNull($this->bus(new NullCorrelationIdProvider())->dispatch(new stdClass())->last(CorrelationIdStamp::class));
        $this->assertNull($this->bus(new CorrelationIdHolder())->dispatch(new stdClass())->last(CorrelationIdStamp::class));

        $own  = new CorrelationIdStamp(CorrelationId::fromString('mine'));
        $kept = $this->bus(new CorrelationIdHolder())->dispatch(new stdClass(), [$own])->last(CorrelationIdStamp::class);
        $this->assertSame($own, $kept);
    }

    public function testStampRoundTripRestoresTheIdInTheHandlerAndTheOuterOneAfterwards(): void
    {
        $sender = new CorrelationIdHolder();
        $sender->begin(new CorrelationContext(CorrelationId::fromString('request-1')));
        $sent = $this->bus($sender, handle: false)->dispatch(new stdClass());

        // Through a transport: serialised, then received by a worker that is busy with another unit of work.
        $serializer = new PhpSerializer();
        $received   = $serializer->decode($serializer->encode($sent))->with(new ReceivedStamp('async'));
        $worker     = new CorrelationIdHolder();
        $worker->begin(new CorrelationContext(CorrelationId::fromString('outer')));

        $this->bus($worker)->dispatch($received);

        $this->assertSame([['request-1', null]], $this->seen);
        $this->assertSame('outer', $worker->current()?->toString());
        $this->assertSame(1, $worker->depth());
    }

    public function testChildIdsRunTheHandlerUnderANewIdCausedByTheStampedOne(): void
    {
        $worker   = new CorrelationIdHolder();
        $envelope = new Envelope(new stdClass(), [new CorrelationIdStamp(CorrelationId::fromString('request-1')), new ReceivedStamp('async')]);

        $this->bus($worker, childIds: true)->dispatch($envelope);

        $this->assertNotSame('request-1', $this->seen[0][0]);
        $this->assertSame('request-1', $this->seen[0][1]);
        $this->assertSame(0, $worker->depth());
    }

    public function testAReceivedMessageWithoutStampGetsANewIdAndAFailingHandlerStillEndsIt(): void
    {
        $worker = new CorrelationIdHolder();
        $bus    = new MessageBus([
            new CorrelationMiddleware($worker),
            new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [function () use ($worker): never {
                $this->seen[] = [$worker->current()?->toString(), null];

                throw new RuntimeException('handler failed');
            }]])),
        ]);

        try {
            $bus->dispatch(new Envelope(new stdClass(), [new ReceivedStamp('async')]));
            $this->fail('The handler failure must propagate');
        } catch (RuntimeException) {
        }

        $this->assertNotNull($this->seen[0][0]);
        $this->assertSame(0, $worker->depth());
    }

    /**
     * A message with no parent stamp (a Scheduler tick, a message dispatched outside any unit of work) runs under a fresh
     * root id with no caused_by, in both modes; caused_by comes only from a stamp.
     */
    #[DataProvider('modes')]
    public function testAMessageWithoutParentRunsUnderAFreshRootId(bool $childIds): void
    {
        $sender = new CorrelationIdHolder();
        $sent   = $this->bus($sender, $childIds, false)->dispatch(new stdClass());
        $worker = new CorrelationIdHolder();

        $this->bus($worker, $childIds)->dispatch($sent->with(new ReceivedStamp('scheduler_default')));
        $this->bus($worker, $childIds)->dispatch(new Envelope(new stdClass(), [new ReceivedStamp('scheduler_default')]));

        $this->assertCount(2, $this->seen);
        foreach ($this->seen as [$id, $causedBy]) {
            $this->assertNotNull($id);
            $this->assertNull($causedBy);
        }
        $this->assertNotSame($this->seen[0][0], $this->seen[1][0]);
        $this->assertSame(0, $worker->depth());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'chain' => [false];
        yield 'child ids' => [true];
    }

    public function testAHostProviderWithoutCausationStampsItsCurrentIdOnly(): void
    {
        $provider = $this->createStub(CorrelationIdProviderInterface::class);
        $provider->method('current')->willReturnOnConsecutiveCalls(CorrelationId::fromString('host-1'), null);
        $bus = new MessageBus([new CorrelationMiddleware($provider)]);

        $this->assertSame('host-1', $bus->dispatch(new stdClass())->last(CorrelationIdStamp::class)?->correlationId->toString());
        $this->assertNull($bus->dispatch(new stdClass())->last(CorrelationIdStamp::class));
    }

    public function testATamperedStampCountsAsNoStamp(): void
    {
        /** @var CorrelationId $bad */
        $bad    = unserialize('O:' . strlen(CorrelationId::class) . ':"' . CorrelationId::class . '":1:{s:5:"value";s:8:"bad id x";}');
        $worker = new CorrelationIdHolder();

        $this->bus($worker)->dispatch(new Envelope(new stdClass(), [new CorrelationIdStamp($bad), new ReceivedStamp('async')]));
        $this->bus($worker)->dispatch(new Envelope(new stdClass(), [new CorrelationIdStamp(CorrelationId::fromString('ok-1'), $bad), new ReceivedStamp('async')]));

        $this->assertNotNull($this->seen[0][0]);
        $this->assertNotSame('bad id x', $this->seen[0][0]);
        $this->assertNull($this->seen[0][1]);
        $this->assertSame(['ok-1', null], $this->seen[1]);
    }

    public function testWithAHostProviderReceivingIsLeftToTheHost(): void
    {
        $provider = $this->createMock(CorrelationIdProviderInterface::class);
        $provider->expects($this->never())->method('current');
        $envelope = new Envelope(new stdClass(), [new CorrelationIdStamp(CorrelationId::fromString('x')), new ReceivedStamp('async')]);

        $result = (new MessageBus([new CorrelationMiddleware($provider)]))->dispatch($envelope);

        $this->assertSame('x', $result->last(CorrelationIdStamp::class)?->correlationId->toString());
    }

    private function bus(CorrelationIdProviderInterface $provider, bool $childIds = false, bool $handle = true): MessageBus
    {
        $middleware = [new CorrelationMiddleware($provider, $childIds)];
        if ($handle) {
            $middleware[] = new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [function () use ($provider): void {
                $context      = $provider instanceof CorrelationIdHolder ? $provider->currentContext() : null;
                $this->seen[] = [$context?->correlationId->toString(), $context?->causedBy?->toString()];
            }]]));
        }

        return new MessageBus($middleware);
    }
}
