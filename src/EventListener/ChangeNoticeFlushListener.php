<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Sync\ChangeNotifier;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Sends the merged change notices when a unit of work is over: after the
 * response left (`kernel.terminate`), after a console command, after a queue
 * message. The notices are queued after the commit of the write
 * ({@see ChangeNotifier}), so they never reach the hub before the data is
 * durable and the visitor's refetch sees the new state.
 */
final class ChangeNoticeFlushListener implements EventSubscriberInterface
{
    public function __construct(private readonly ChangeNotifier $notifier)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            WorkerMessageHandledEvent::class => 'flush',
            WorkerMessageFailedEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->notifier->flush();
    }
}
