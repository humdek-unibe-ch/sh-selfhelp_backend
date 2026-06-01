<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\EventListener;

use App\Plugin\Event\PluginRealtimePermissionEvent;
use App\Service\CMS\UserPermissionService;
use App\Service\Mercure\MercureTopicResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Grants subscription to the admin plugin state topic
 * ({@see MercureTopicResolver::pluginAdminStateTopic()}) to any user
 * who holds the `admin.plugins.manage` permission.
 *
 * Wired through the existing {@see PluginRealtimePermissionEvent}
 * dispatched by {@see \App\Controller\Api\V1\Auth\AuthEventsController}
 * when minting a subscriber JWT. Users without the permission never
 * receive the topic IRI, so the host stays deny-by-default.
 */
#[AsEventListener(event: PluginRealtimePermissionEvent::class)]
final class AdminPluginStateTopicGrant
{
    public function __construct(
        private readonly UserPermissionService $permissions,
        private readonly MercureTopicResolver $topics,
    ) {
    }

    public function __invoke(PluginRealtimePermissionEvent $event): void
    {
        $userPermissions = $this->permissions->getUserPermissions($event->user);
        if (!in_array('admin.plugins.manage', $userPermissions, true)) {
            return;
        }
        $event->allowTopic($this->topics->pluginAdminStateTopic());
    }
}
