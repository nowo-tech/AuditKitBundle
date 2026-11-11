<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Avatar;

use Nowo\AuditKitBundle\Avatar\ActionLogAvatars;
use Nowo\AuditKitBundle\Avatar\ActionLogAvatarsResetSubscriber;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuditKitBundle\Tests\Support\CountingAvatarResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function count;

final class ActionLogAvatarsResetSubscriberTest extends TestCase
{
    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        $this->assertSame(
            [
                KernelEvents::REQUEST             => ['onMainRequestBoundary', 4096],
                KernelEvents::FINISH_REQUEST      => ['onMainRequestBoundary', -4096],
                WorkerMessageReceivedEvent::class => ['reset', 4096],
                WorkerMessageHandledEvent::class  => ['reset', -4096],
                WorkerMessageFailedEvent::class   => ['reset', -4096],
                'console.terminate'               => ['reset', -4096],
            ],
            ActionLogAvatarsResetSubscriber::getSubscribedEvents(),
        );
    }

    public function testMainRequestBoundariesEmptyTheMemoAndSubRequestsKeepIt(): void
    {
        $resolver   = new CountingAvatarResolver(['1' => '/files/1/s64']);
        $avatars    = new ActionLogAvatars($resolver);
        $subscriber = new ActionLogAvatarsResetSubscriber($avatars);
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $user       = $this->user(1);

        $avatars->urlOf($user);
        $subscriber->onMainRequestBoundary(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onMainRequestBoundary(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $avatars->urlOf($user);
        $this->assertSame(1, $this->callCount($resolver), 'A sub-request keeps the memo of its main request.');

        $subscriber->onMainRequestBoundary(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $avatars->urlOf($user);
        $this->assertSame(2, $this->callCount($resolver), 'kernel.finish_request of the main request empties the memo.');

        $subscriber->onMainRequestBoundary(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $avatars->urlOf($user);
        $this->assertSame(3, $this->callCount($resolver), 'kernel.request of a main request empties the memo.');

        $subscriber->reset();
        $avatars->urlOf($user);
        $this->assertSame(4, $this->callCount($resolver), 'Messenger / console boundaries empty the memo.');
    }

    private function callCount(CountingAvatarResolver $resolver): int
    {
        return count($resolver->calls);
    }

    private function user(int $id): KernelUser
    {
        $user     = new KernelUser('user' . $id . '@example.com');
        $user->id = $id;

        return $user;
    }
}
