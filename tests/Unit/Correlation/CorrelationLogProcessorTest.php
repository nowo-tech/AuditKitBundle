<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\Monolog\CorrelationLogProcessor;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use PHPUnit\Framework\TestCase;

final class CorrelationLogProcessorTest extends TestCase
{
    public function testOutsideAUnitOfWorkTheRecordIsUnchanged(): void
    {
        $record = $this->record();

        $this->assertSame($record, (new CorrelationLogProcessor(new NullCorrelationIdProvider()))($record));
        $this->assertSame($record, (new CorrelationLogProcessor(new CorrelationIdHolder()))($record));
    }

    public function testAddsTheCorrelationIdAndCausedBy(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('child'), CorrelationId::fromString('parent')));

        $record = (new CorrelationLogProcessor($holder))($this->record());

        $this->assertSame(['existing' => 1, 'correlation_id' => 'child', 'caused_by' => 'parent'], $record->extra);
    }

    public function testAProviderWithoutCausationAddsTheIdOnly(): void
    {
        $provider = $this->createStub(CorrelationIdProviderInterface::class);
        $provider->method('current')->willReturn(CorrelationId::fromString('only-id'));

        $this->assertSame(['existing' => 1, 'correlation_id' => 'only-id'], (new CorrelationLogProcessor($provider))($this->record())->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'message', [], ['existing' => 1]);
    }
}
