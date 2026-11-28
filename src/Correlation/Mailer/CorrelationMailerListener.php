<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Correlation\Mailer;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\Headers;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Message;

/**
 * Since 2.2 (opt-in, `correlation.mailer: true`): adds the correlation header (`correlation.header_name`, default
 * `X-Correlation-Id`) with the current id to every e-mail sent inside a unit of work, unless the message already
 * carries it. Outside any unit of work nothing is added (no id is generated for it). A message queued through
 * Messenger gets the header when it is queued, so the id of the unit of work that sent it is kept.
 *
 * Stateless: the provider is asked on every message.
 *
 * @since 2.2.0
 */
final readonly class CorrelationMailerListener
{
    public function __construct(
        private CorrelationIdProviderInterface $provider,
        private string $headerName = Headers::CORRELATION_ID,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        $id      = $this->provider->current();
        if (!$message instanceof Message || !$id instanceof CorrelationId || $message->getHeaders()->has($this->headerName)) {
            return;
        }

        $message->getHeaders()->addTextHeader($this->headerName, $id->toString());
    }
}
