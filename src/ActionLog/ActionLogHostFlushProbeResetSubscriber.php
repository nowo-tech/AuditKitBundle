<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\ActionLog;

use Nowo\AuditKitBundle\Avatar\ActionLogAvatarsResetSubscriber;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Since 1.18: empties the host-flush probe's "already reported" memo at every unit-of-work boundary, so each request,
 * message or command reports its own pending host changes once, and nothing survives from one to the next in a
 * long-running worker with the kernel kept alive and no services resetter (FrankenPHP worker mode, kernel reset false;
 * Messenger consumers). Same boundaries as {@see ActionLogAvatarsResetSubscriber}, plus
 * the start of a console command. The probe itself is read-only: resetting it touches no entity manager.
 *
 * Registered only in implicit host mode (`action_log.flush_mode` unset).
 *
 * @internal registered by the bundle
 */
final readonly class ActionLogHostFlushProbeResetSubscriber implements EventSubscriberInterface
{
    public const WORKER_MESSAGE_RECEIVED = WorkerMessageReceivedEvent::class;
    public const WORKER_MESSAGE_HANDLED  = WorkerMessageHandledEvent::class;
    public const WORKER_MESSAGE_FAILED   = WorkerMessageFailedEvent::class;

    public function __construct(private HostFlushProbe $probe)
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
            ConsoleEvents::COMMAND        => ['reset', 4096],
            ConsoleEvents::TERMINATE      => ['reset', -4096],
        ];
    }

    /** Start (`kernel.request`) and end (`kernel.finish_request`) of a main request; sub-requests are ignored. */
    public function onMainRequestBoundary(KernelEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->probe->reset();
        }
    }

    /** Start / end of a Messenger message, start / end of a console command. */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
