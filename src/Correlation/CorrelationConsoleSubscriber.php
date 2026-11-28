<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation;

use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Since 2.2 (with `correlation.provider: true`): a new correlation id for every console command run.
 *
 * `console.command` starts a context with a new id, `console.terminate` (which also follows a failed command) ends it.
 * A command run from another command gets its own id and gives the outer one back when it ends.
 *
 * @since 2.2.0
 *
 * @internal registered by the bundle
 */
final readonly class CorrelationConsoleSubscriber implements EventSubscriberInterface
{
    public function __construct(private CorrelationIdHolder $holder)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND   => ['onCommand', 4096],
            ConsoleEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onCommand(): void
    {
        $this->holder->begin(new CorrelationContext(CorrelationId::generate()));
    }

    public function onTerminate(): void
    {
        $this->holder->end();
    }
}
