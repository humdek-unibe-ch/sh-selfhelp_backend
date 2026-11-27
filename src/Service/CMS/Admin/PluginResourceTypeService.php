<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Plugin\Event\DataAccessResourcesEvent;
use App\Plugin\Resource\RegisteredResourceTypes;
use App\Service\Core\BaseService;
use App\Service\Security\CallerHoldingsService;
use App\Service\Security\DataAccessSecurityService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Data-access resource types that enabled plugins add (H6), for the
 * "Permissions for role" screen: the types next to pages, data tables,
 * assets and groups, and the resources of one type. A plugin declares the
 * type as an entry of the `resourceTypes` lookup in its manifest (written by
 * the installer, tagged with the plugin) and lists its resources through
 * {@see DataAccessResourcesEvent}.
 *
 * The list of types is {@see RegisteredResourceTypes}: the one answer to "which
 * resource types do enabled plugins register". This class adds nothing to it.
 *
 * The resource list is shaped for the caller (contract C9, F232): a
 * non-superuser sees only the items they hold the view grant on, as with the
 * pages list (rule 4.6d), and each item says whether the caller may hand it
 * out (`can_assign`: they hold every bit of it, rule 4.6f).
 */
class PluginResourceTypeService extends BaseService
{
    /** Every bit a grant can carry: create, read, update, delete. */
    private const ALL_BITS = DataAccessSecurityService::PERMISSION_CREATE
        | DataAccessSecurityService::PERMISSION_READ
        | DataAccessSecurityService::PERMISSION_UPDATE
        | DataAccessSecurityService::PERMISSION_DELETE;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly CallerHoldingsService $callerHoldings,
    ) {
    }

    /**
     * @return list<array{id: int, code: string, label: string, plugin_id: string}>
     */
    public function listTypes(): array
    {
        return $this->registeredTypes->all();
    }

    /**
     * @return list<array{id: int, name: string, description: string|null, can_assign: bool}>
     * @throws \App\Exception\ServiceException 404 when no enabled plugin declares the type
     */
    public function listResources(string $typeCode, int $callerId): array
    {
        $typeId = $this->registeredTypes->lookupId($typeCode);
        if ($typeId === null) {
            $this->throwNotFound('Unknown plugin resource type');
        }

        $event = new DataAccessResourcesEvent($typeCode);
        $this->eventDispatcher->dispatch($event);

        // The superuser holds every bit of everything (CallerHoldingsService::grantBits()).
        $resources = [];
        foreach ($event->getResources() as $resource) {
            $held = $this->callerHoldings->grantBits($callerId, $typeId, $resource['id']);
            if (($held & DataAccessSecurityService::PERMISSION_READ) === 0) {
                continue;
            }
            $resources[] = $resource + ['can_assign' => ($held & self::ALL_BITS) === self::ALL_BITS];
        }

        return $resources;
    }
}
