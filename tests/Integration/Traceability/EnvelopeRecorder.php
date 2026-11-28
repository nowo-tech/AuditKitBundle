<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/** Test-only middleware listed after the correlation one: remembers every envelope that reaches the handlers. */
final class EnvelopeRecorder implements MiddlewareInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->envelopes[] = $envelope;

        return $stack->next()->handle($envelope, $stack);
    }
}
