<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Plugin\Event\PluginRealtimePermissionEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Realtime\PluginRealtimePublisher;
use App\Plugin\Realtime\PluginRealtimePublisherInterface;
use App\Plugin\Registry\PluginRegistryService;
use App\Service\CMS\UserPermissionService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Realtime to one user (contract C18): puts the per-user topics of the enabled plugins into the
 * subscriber token of the signed-in user, and only theirs.
 *
 * A plugin publishes to one user with `PluginRealtimePublisherInterface::publish()` and
 * `audience: 'user'`; the topic is `…/plugin/<pluginId>/<topicKey>/userId/<id>`. This listener
 * runs when {@see \App\Controller\Api\V1\Auth\AuthEventsController} mints a token and grants,
 * for every topic key an enabled plugin declares in its manifest (`realtimeTopics`), the
 * topic with the id of the user the token is for. Another user's id never gets in, and a guest
 * has no token entry because the controller dispatches the event for signed-in users only.
 * A topic that declares a `requiredPermission` is granted only to a user who holds it.
 *
 * The publisher keeps the parameter name `userId` for this audience, so nothing else is ever
 * published on a topic that this listener grants.
 */
#[AsEventListener(event: PluginRealtimePermissionEvent::class)]
final class PluginUserTopicGrant
{
    public function __construct(
        private readonly PluginRegistryService $plugins,
        private readonly PluginRealtimePublisherInterface $publisher,
        private readonly UserPermissionService $permissions,
    ) {
    }

    public function __invoke(PluginRealtimePermissionEvent $event): void
    {
        $userId = (int) $event->user->getId();
        if ($userId <= 0) {
            return;
        }

        $held = null;
        foreach ($this->plugins->getEnabled() as $plugin) {
            foreach ((new PluginManifest($plugin->getManifestJson()))->getRealtimeTopics() as $topic) {
                $key = $topic['key'] ?? null;
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $required = $topic['requiredPermission'] ?? null;
                if (is_string($required) && $required !== '') {
                    $held ??= $this->permissions->getUserPermissions($event->user);
                    if (!in_array($required, $held, true)) {
                        continue;
                    }
                }

                $event->allowTopic($this->publisher->resolveTopicIri(
                    $plugin->getPluginId(),
                    $key,
                    [PluginRealtimePublisher::USER_PARAM => $userId],
                ));
            }
        }
    }
}
