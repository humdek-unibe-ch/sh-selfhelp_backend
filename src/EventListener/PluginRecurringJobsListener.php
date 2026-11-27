<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Plugin\Event\Lifecycle\PluginDisabledEvent;
use App\Plugin\Event\Lifecycle\PluginEnabledEvent;
use App\Plugin\Event\Lifecycle\PluginInstalledEvent;
use App\Plugin\Event\Lifecycle\PluginLifecycleEvent;
use App\Plugin\Event\Lifecycle\PluginUninstalledEvent;
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Plugin\ScheduledJob\PluginRecurringJobs;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the queued recurring jobs of a plugin in line with its lifecycle (contract C25).
 *
 * The lifecycle orchestrators dispatch these events after the state change has committed.
 * A plugin that is installed, updated or enabled gets exactly one queued job per
 * `recurringJobs` entry of its manifest; a plugin that is disabled or uninstalled loses its
 * queued ones. The work is best-effort: a failure here is logged and never undoes or fails the
 * lifecycle change, because the next enable or update repairs the queue.
 */
final class PluginRecurringJobsListener
{
    public function __construct(
        private readonly PluginRecurringJobs $recurringJobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: PluginInstalledEvent::class)]
    #[AsEventListener(event: PluginEnabledEvent::class)]
    #[AsEventListener(event: PluginUpdatedEvent::class)]
    public function onActive(PluginLifecycleEvent $event): void
    {
        try {
            $this->recurringJobs->sync($event->plugin);
        } catch (\Throwable $e) {
            $this->logger->error('Could not sync the recurring jobs of a plugin.', [
                'plugin' => $event->plugin->getPluginId(),
                'exception' => $e,
            ]);
        }
    }

    #[AsEventListener(event: PluginDisabledEvent::class)]
    #[AsEventListener(event: PluginUninstalledEvent::class)]
    public function onInactive(PluginLifecycleEvent $event): void
    {
        try {
            $this->recurringJobs->remove($event->plugin);
        } catch (\Throwable $e) {
            $this->logger->error('Could not remove the recurring jobs of a plugin.', [
                'plugin' => $event->plugin->getPluginId(),
                'exception' => $e,
            ]);
        }
    }
}
