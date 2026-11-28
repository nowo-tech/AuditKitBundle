<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Nowo\AuditKitBundle\Correlation\CorrelationContextProviderInterface;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;

/**
 * Since 2.2: adds `extra.correlation_id` (and `extra.caused_by` when the provider tracks causation and the unit of work
 * has one) to every Monolog record written inside a request, a handled message or a command. Outside any unit of work
 * the record is left as it is: no id is generated for it.
 *
 * Registered with the `monolog.processor` tag when monolog/monolog 3 is installed and `correlation.monolog` is true
 * (the default). Stateless: the provider is asked on every record.
 *
 * @since 2.2.0
 */
final readonly class CorrelationLogProcessor implements ProcessorInterface
{
    public function __construct(private CorrelationIdProviderInterface $provider)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->provider instanceof CorrelationContextProviderInterface ? $this->provider->currentContext() : null;
        $id      = $context instanceof CorrelationContext ? $context->correlationId : $this->provider->current();
        if (!$id instanceof CorrelationId) {
            return $record;
        }

        $extra                   = $record->extra;
        $extra['correlation_id'] = $id->toString();
        if ($context?->causedBy instanceof CorrelationId) {
            $extra['caused_by'] = $context->causedBy->toString();
        }

        return $record->with(extra: $extra);
    }
}
