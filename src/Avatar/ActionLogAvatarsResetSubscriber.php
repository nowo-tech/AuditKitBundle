<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Avatar;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Since 1.14.1: empties the per-user avatar memo ({@see ActionLogAvatars}) at every unit-of-work boundary, so a
 * long-running worker with the kernel kept alive and no services resetter (FrankenPHP worker mode, kernel reset false;
 * Messenger consumers) never shows one request's or tenant's avatar URL in another and the memo does not grow for the
 * life of the process:
 *
 * - HTTP: at the start of every main request and at its `kernel.finish_request` (sub-requests keep the memo of their
 *   main request);
 * - Messenger: when a message is received, handled or failed (event names as strings, Messenger stays optional);
 * - console: at `console.terminate`.
 *
 * @internal registered by the bundle
 */
final readonly class ActionLogAvatarsResetSubscriber implements EventSubscriberInterface
{
    public const WORKER_MESSAGE_RECEIVED = WorkerMessageReceivedEvent::class;
    public const WORKER_MESSAGE_HANDLED  = WorkerMessageHandledEvent::class;
    public const WORKER_MESSAGE_FAILED   = WorkerMessageFailedEvent::class;

    public function __construct(private ActionLogAvatars $avatars)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST         => ['onMainRequestBoundary', 4096],
            KernelEvents::FINISH_REQUEST  => ['onMainRequestBoundary', -4096],
            self::WORKER_MESSAGE_RECEIVED => ['reset', 4096],
            self::WORKER_MESSAGE_HANDLED  => ['reset', -4096],
            self::WORKER_MESSAGE_FAILED   => ['reset', -4096],
            ConsoleEvents::TERMINATE      => ['reset', -4096],
        ];
    }

    /** Start (`kernel.request`) and end (`kernel.finish_request`) of a main request; sub-requests are ignored. */
    public function onMainRequestBoundary(KernelEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->avatars->reset();
        }
    }

    /** Start / end of a Messenger message, end of a console command. */
    public function reset(): void
    {
        $this->avatars->reset();
    }
}
