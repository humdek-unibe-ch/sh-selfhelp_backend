<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

use App\Repository\RoleDataAccessRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\CreatorGrantService;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Security\ResourceGrantService;
use App\Service\Sync\ChangeNoticeService;

/**
 * The host's answer to {@see PluginResourceServiceInterface}: one call per
 * event of a plugin item's life, built from the services every core resource
 * already uses (grants, cache scopes, change notices).
 */
final class PluginResourceService implements PluginResourceServiceInterface
{
    public function __construct(
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly CacheService $cache,
        private readonly ChangeNoticeService $notices,
        private readonly ResourceGrantService $grants,
        private readonly DataAccessSecurityService $dataAccess,
        private readonly CreatorGrantService $creatorGrants,
        private readonly RoleDataAccessRepository $roleDataAccess,
        private readonly ContentVersionPins $pins,
    ) {
    }

    public function changed(string $type, int $id, bool $published): void
    {
        $this->registeredTypes->assertRegistered($type);

        $this->cache->invalidateResourceScope($type, $id);
        $this->notices->resourceChanged($type, $id, $published);
    }

    public function deleted(string $type, int $id): void
    {
        $this->registeredTypes->assertRegistered($type);

        // The notice goes first: its audience (the roles with a grant on the item)
        // is read from the grants that are removed next.
        $this->notices->resourceChanged($type, $id, true);
        foreach ($this->grants->revokeAll($type, $id) as $roleId) {
            $this->dataAccess->invalidateRolePermissions($roleId);
        }
        $this->cache->invalidateResourceScope($type, $id);
    }

    public function grantCreator(string $createPermission, string $type, int $id): void
    {
        $this->registeredTypes->assertRegistered($type);

        $this->creatorGrants->grantCallerRolesHolding($createPermission, $type, $id);
    }

    public function rightsFor(string $type, array $ids, int $userId): array
    {
        $this->registeredTypes->assertRegistered($type);
        $typeId = $this->registeredTypes->lookupId($type);

        $wanted = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $wanted[$id] = $id;
            }
        }
        if ($wanted === [] || $typeId === null) {
            return [];
        }

        $superuser = $this->dataAccess->userIsSuperuser($userId);
        $bits = $superuser ? [] : $this->roleDataAccess->getUserPermissionsForResources($userId, $typeId, array_values($wanted));

        $rights = [];
        foreach ($wanted as $id) {
            $held = $bits[$id] ?? 0;
            $rights[$id] = [
                'can_view' => $superuser || ($held & DataAccessSecurityService::PERMISSION_READ) !== 0,
                'can_update' => $superuser || ($held & DataAccessSecurityService::PERMISSION_UPDATE) !== 0,
                'can_delete' => $superuser || ($held & DataAccessSecurityService::PERMISSION_DELETE) !== 0,
            ];
        }

        return $rights;
    }

    public function pinnedVersion(int $userId, int $dataTableId, string $type, int $id): ?int
    {
        $this->registeredTypes->assertRegistered($type);

        return $this->pins->pinnedVersion($userId, $dataTableId, $type, $id);
    }
}
