<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation;

use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;

/**
 * Since 2.2: optional companion of nowo-tech/correlation-contract's {@see CorrelationIdProviderInterface} for providers
 * that also track causation. When the provider the host binds to `CorrelationIdProviderInterface` implements it, the
 * action log fills `caused_by` and the log processor adds `caused_by` next to `correlation_id`; otherwise only the
 * correlation id is recorded.
 *
 * The bundle's opt-in {@see CorrelationIdHolder} implements it.
 *
 * @since 2.2.0
 */
interface CorrelationContextProviderInterface
{
    /** The correlation id and the optional `causedBy` of the current unit of work, null outside any. */
    public function currentContext(): ?CorrelationContext;
}
