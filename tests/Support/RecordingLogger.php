<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Test double: keeps every log record as [level, message, context]. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string|Stringable, array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
