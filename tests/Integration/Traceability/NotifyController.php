<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Traceability;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Calls an outgoing API and sends an e-mail in the same request. */
final readonly class NotifyController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $this->httpClient->request('POST', 'https://api.example.test/hook')->getStatusCode();
        $this->mailer->send((new Email())->from('app@example.test')->to('user@example.test')->subject('Hi')->text('Hello'));

        return new JsonResponse(['ok' => true]);
    }
}
