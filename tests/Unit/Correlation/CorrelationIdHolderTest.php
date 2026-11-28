<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;

final class CorrelationIdHolderTest extends TestCase
{
    public function testOutsideAnyUnitOfWorkThereIsNoIdAndGeneratedIdsAreNotStored(): void
    {
        $holder = new CorrelationIdHolder();

        $this->assertNull($holder->current());
        $this->assertNull($holder->currentContext());
        $first = $holder->currentOrGenerate();
        $this->assertFalse($first->equals($holder->currentOrGenerate()));
        $this->assertNull($holder->current());
        $this->assertSame(0, $holder->depth());
    }

    public function testNestedUnitsRestoreTheOuterOne(): void
    {
        $holder = new CorrelationIdHolder();
        $outer  = new CorrelationContext(CorrelationId::fromString('outer'));
        $inner  = new CorrelationContext(CorrelationId::fromString('inner'), CorrelationId::fromString('outer'));

        $holder->begin($outer);
        $holder->begin($inner);
        $this->assertSame($inner, $holder->currentContext());
        $this->assertSame('inner', $holder->currentOrGenerate()->toString());
        $this->assertSame(2, $holder->depth());

        $holder->end();
        $this->assertSame('outer', $holder->current()?->toString());

        $holder->end();
        $holder->end();
        $this->assertNull($holder->current());
    }

    public function testResetForgetsEverything(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::generate()));
        $holder->begin(new CorrelationContext(CorrelationId::generate()));

        $holder->reset();

        $this->assertSame(0, $holder->depth());
        $this->assertNull($holder->current());
    }

    public function testDepthIsBounded(): void
    {
        $holder = new CorrelationIdHolder();
        for ($i = 0; $i < CorrelationIdHolder::MAX_DEPTH + 10; ++$i) {
            $holder->begin(new CorrelationContext(CorrelationId::fromString('id' . $i)));
        }

        $this->assertSame(CorrelationIdHolder::MAX_DEPTH, $holder->depth());
        $this->assertSame('id' . (CorrelationIdHolder::MAX_DEPTH + 9), $holder->current()?->toString());
    }
}
