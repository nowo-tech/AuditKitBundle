<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Correlation;

use Nowo\AuditKitBundle\Correlation\CorrelationIdHolder;
use Nowo\AuditKitBundle\Correlation\Mailer\CorrelationMailerListener;
use Nowo\CorrelationContract\CorrelationContext;
use Nowo\CorrelationContract\CorrelationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class CorrelationMailerListenerTest extends TestCase
{
    public function testAddsTheConfiguredHeaderInsideAUnitOfWorkOnly(): void
    {
        $holder   = new CorrelationIdHolder();
        $listener = new CorrelationMailerListener($holder, 'X-Request-Id');

        $outside = $this->email();
        $listener($this->event($outside));
        $this->assertFalse($outside->getHeaders()->has('X-Request-Id'));

        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-5')));
        $inside = $this->email();
        $listener($this->event($inside));
        $listener($this->event($inside));
        $this->assertSame('req-5', $inside->getHeaders()->get('X-Request-Id')?->getBodyAsString());
        $this->assertCount(1, iterator_to_array($inside->getHeaders()->all('x-request-id'), false));

        $own = $this->email();
        $own->getHeaders()->addTextHeader('X-Request-Id', 'mine');
        $listener($this->event($own));
        $this->assertSame('mine', $own->getHeaders()->get('X-Request-Id')?->getBodyAsString());

        $raw = new RawMessage('raw');
        $listener(new MessageEvent($raw, new Envelope(new Address('a@example.test'), [new Address('b@example.test')]), 'null'));
        $this->assertSame('raw', $raw->toString());
    }

    public function testDefaultHeaderName(): void
    {
        $holder = new CorrelationIdHolder();
        $holder->begin(new CorrelationContext(CorrelationId::fromString('req-6')));
        $email = $this->email();

        (new CorrelationMailerListener($holder))($this->event($email));

        $this->assertSame('req-6', $email->getHeaders()->get('X-Correlation-Id')?->getBodyAsString());
    }

    private function email(): Email
    {
        return (new Email())->from('a@example.test')->to('b@example.test')->subject('s')->text('t');
    }

    private function event(Email $email): MessageEvent
    {
        return new MessageEvent($email, Envelope::create($email), 'null');
    }
}
