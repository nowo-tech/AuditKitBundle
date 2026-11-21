<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/** Test helper: the `E_USER_DEPRECATED` messages a callable triggers (suppressed ones included), in order. */
trait CapturesDeprecations
{
    /**
     * @param callable(): mixed $run
     *
     * @return list<string>
     */
    private static function deprecationsOf(callable $run): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper: restored in the finally below)
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    /**
     * The messages of this bundle (its package or bundle name).
     *
     * @param list<string> $messages
     *
     * @return list<string>
     */
    private static function ownDeprecations(array $messages): array
    {
        return array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'nowo-tech/audit-kit-bundle') || str_contains($message, 'NowoAuditKitBundle')));
    }
}
