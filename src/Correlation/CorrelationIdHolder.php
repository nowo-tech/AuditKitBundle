<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation;

use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_pop;
use function count;
use function end;

/**
 * Since 2.2: the bundle's opt-in correlation id provider (`nowo_audit_kit.correlation.provider: true`), for hosts that
 * have no provider of their own. It holds the {@see CorrelationContext} of the current unit of work and nothing else.
 *
 * The bundle's listeners drive it: {@see CorrelationRequestSubscriber} starts a context at every main request and
 * clears it at `kernel.terminate`, {@see CorrelationConsoleSubscriber} starts one per console command,
 * {@see Messenger\CorrelationMiddleware} restores the stamped one while a received message is handled. Nested units of
 * work (a command run from a command, a message handled during a request) stack: {@see end()} restores the outer one.
 *
 * Worker mode (FrankenPHP, kernel kept alive, no services resetter; Messenger consumers): the only state is the stack of
 * the current unit of work. It is emptied when a main request starts and at `kernel.terminate`, popped in a `finally`
 * after every handled message, and emptied by `kernel.reset` ({@see reset()}). The stack is bounded by
 * {@see MAX_DEPTH}: a unit that never ends cannot make it grow for the life of the process.
 *
 * {@see currentOrGenerate()} never stores the id it creates outside a unit of work (contract rule 3).
 *
 * @since 2.2.0
 */
final class CorrelationIdHolder implements CorrelationIdProviderInterface, CorrelationContextProviderInterface, ResetInterface
{
    /** Deepest nesting kept; beginning a unit beyond it replaces the innermost one. */
    public const MAX_DEPTH = 32;

    /** @var list<CorrelationContext> outermost first */
    private array $stack = [];

    public function current(): ?CorrelationId
    {
        return $this->currentContext()?->correlationId;
    }

    public function currentOrGenerate(): CorrelationId
    {
        return $this->current() ?? CorrelationId::generate();
    }

    public function currentContext(): ?CorrelationContext
    {
        $top = end($this->stack);

        return $top === false ? null : $top;
    }

    /** Starts a (nested) unit of work under `$context`. */
    public function begin(CorrelationContext $context): void
    {
        if (count($this->stack) >= self::MAX_DEPTH) {
            array_pop($this->stack);
        }

        $this->stack[] = $context;
    }

    /** Ends the innermost unit of work: the outer one, if any, is current again. */
    public function end(): void
    {
        array_pop($this->stack);
    }

    /** Forgets every unit of work (start / end of a main request, `kernel.reset`). */
    public function reset(): void
    {
        $this->stack = [];
    }

    /** Number of nested units of work (0 outside any). */
    public function depth(): int
    {
        return count($this->stack);
    }
}
