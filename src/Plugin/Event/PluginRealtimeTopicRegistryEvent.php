<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by `PluginRealtimePublisher` when it builds the catalog of
 * known plugin realtime topics. Plugins register the topics they will
 * publish/subscribe to so:
 *
 *   - The admin UI's "Realtime topics" tab can list them.
 *   - Subscriber JWTs can be scoped to the topics the user is allowed
 *     to subscribe to (via `PluginRealtimePermissionEvent`).
 *   - The doctor command can detect drift between manifest declarations
 *     and runtime registrations.
 *
 * Topics must be declared in `plugin.json` under `realtimeTopics`.
 */
final class PluginRealtimeTopicRegistryEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   key: string,
     *   description: string,
     *   requiredPermission: string|null,
     *   payloadSchemaPath: string|null,
     * }>
     */
    private array $topics = [];

    public function addTopic(
        string $pluginId,
        string $key,
        string $description,
        ?string $requiredPermission = null,
        ?string $payloadSchemaPath = null,
    ): void {
        $this->topics[] = [
            'pluginId' => $pluginId,
            'key' => $key,
            'description' => $description,
            'requiredPermission' => $requiredPermission,
            'payloadSchemaPath' => $payloadSchemaPath,
        ];
    }

    /**
     * @return array<int, array{
     *   pluginId: string,
     *   key: string,
     *   description: string,
     *   requiredPermission: string|null,
     *   payloadSchemaPath: string|null,
     * }>
     */
    public function getTopics(): array
    {
        return $this->topics;
    }
}
