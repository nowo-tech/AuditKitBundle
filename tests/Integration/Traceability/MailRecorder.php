<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Message;

/** Test-only: remembers the correlation header of every e-mail, after the bundle's listener ran. */
final class MailRecorder
{
    /** @var list<string|null> */
    public array $headers = [];

    public string $headerName = 'X-Request-Id';

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if ($message instanceof Message && !$event->isQueued()) {
            $this->headers[] = $message->getHeaders()->get($this->headerName)?->getBodyAsString();
        }
    }
}
