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
use App\Plugin\Event\Lifecycle\PluginOperationProgressEvent;
use App\Plugin\Event\Lifecycle\PluginPurgedEvent;
use App\Plugin\Event\Lifecycle\PluginUninstalledEvent;
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Service\Mercure\MercureTopicResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes plugin admin state updates on the single Mercure topic
 * resolved by {@see MercureTopicResolver::pluginAdminStateTopic()}.
 *
 * One topic carries every event that should invalidate the admin
 * plugin manager UI's React Query caches:
 *
 *   - `plugin-installed` / `plugin-enabled` / `plugin-disabled` /
 *     `plugin-updated` / `plugin-uninstalled` / `plugin-purged`
 *     — from {@see PluginLifecycleEvent} subclasses, dispatched by
 *     the lifecycle orchestrators after a successful state transition.
 *   - `plugin-operation-progress` — from
 *     {@see PluginOperationProgressEvent}, dispatched by
 *     {@see \App\Plugin\Lifecycle\PluginOperationRecorder} on every
 *     status transition (running → succeeded / failed / cancelled).
 *
 * Subscribers (admin shell via the BFF `/api/plugins/events` route)
 * invalidate their cached queries on event arrival, so the admin UI
 * reacts instantly without polling. Authorization for the topic is
 * granted by {@see AdminPluginStateTopicGrant}.
 *
 * Hub failures are logged and swallowed — realtime is best-effort and
 * the admin can always trigger a manual refetch.
 */
final class PluginStateMercurePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureTopicResolver $topics,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: PluginInstalledEvent::class)]
    public function onInstalled(PluginInstalledEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-installed');
    }

    #[AsEventListener(event: PluginEnabledEvent::class)]
    public function onEnabled(PluginEnabledEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-enabled');
    }

    #[AsEventListener(event: PluginDisabledEvent::class)]
    public function onDisabled(PluginDisabledEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-disabled');
    }

    #[AsEventListener(event: PluginUpdatedEvent::class)]
    public function onUpdated(PluginUpdatedEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-updated');
    }

    #[AsEventListener(event: PluginUninstalledEvent::class)]
    public function onUninstalled(PluginUninstalledEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-uninstalled');
    }

    #[AsEventListener(event: PluginPurgedEvent::class)]
    public function onPurged(PluginPurgedEvent $event): void
    {
        $this->publishLifecycle($event, 'plugin-purged');
    }

    #[AsEventListener(event: PluginOperationProgressEvent::class)]
    public function onProgress(PluginOperationProgressEvent $event): void
    {
        $op = $event->operation;
        $this->publish('plugin-operation-progress', [
            'operationId' => $op->getId(),
            'pluginId' => $op->getPluginId(),
            'type' => $op->getType(),
            'status' => $op->getStatus(),
            'stage' => $event->stage,
            'percent' => $event->percent,
        ]);
    }

    private function publishLifecycle(PluginLifecycleEvent $event, string $eventName): void
    {
        $this->publish($eventName, [
            'pluginId' => $event->plugin->getPluginId(),
            'version' => $event->plugin->getVersion(),
            'enabled' => $event->plugin->isEnabled(),
            'operationId' => $event->operation?->getId(),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publish(string $eventName, array $payload): void
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->hub->publish(new Update(
                $this->topics->pluginAdminStateTopic(),
                $body,
                true,
                null,
                $eventName,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish plugin admin state update to Mercure', [
                'event' => $eventName,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
