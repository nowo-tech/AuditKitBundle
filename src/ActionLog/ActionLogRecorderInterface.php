<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use BackedEnum;

/**
 * Port for recording action log rows; the default is {@see ActionLogRecorder} (Doctrine ORM). Type-hint this
 * interface to swap the recorder (asynchronous via Messenger, a test double) by aliasing it.
 *
 * @since 1.12.0
 */
interface ActionLogRecorderInterface
{
    /**
     * Creates and persists a row (not flushed).
     *
     * @param array<string, mixed> $context UI-facing metadata (names, roles, UUIDs, …)
     */
    public function record(
        string|BackedEnum $action,
        ?object $actor = null,
        ?object $subject = null,
        array $context = [],
        ?string $scope = null,
    ): AbstractActionLog;

    /**
     * Creates, persists and flushes a row.
     *
     * @param array<string, mixed> $context
     */
    public function recordAndFlush(
        string|BackedEnum $action,
        ?object $actor = null,
        ?object $subject = null,
        array $context = [],
        ?string $scope = null,
    ): AbstractActionLog;
}
