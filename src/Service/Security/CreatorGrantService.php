<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminDataAccessService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grants full data-access (create/read/update/delete) to the caller's roles that
 * hold a named route permission, excluding the admin role (it bypasses grants).
 *
 * Used for new pages (F14: `admin.page.create`), new assets
 * (`admin.asset.create`) and new groups (`admin.group.create`): whoever
 * creates an item can manage it (rule 6b). Writing goes through {@see AdminDataAccessService}
 * so each change is logged and role permission caches are dropped.
 */
final class CreatorGrantService
{
    public const FULL_ACCESS = DataAccessSecurityService::PERMISSION_CREATE
        | DataAccessSecurityService::PERMISSION_READ
        | DataAccessSecurityService::PERMISSION_UPDATE
        | DataAccessSecurityService::PERMISSION_DELETE;

    private ?int $adminRoleId = null;
    private bool $adminRoleIdLoaded = false;

    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly AdminDataAccessService $dataAccess,
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
    ) {
    }

    /**
     * Grant full access on a resource to the caller's roles that hold `$permissionName`.
     * No-op when there is no signed-in caller or no matching roles.
     *
     * @param string $permissionName Route permission such as `admin.page.create`
     * @param string $resourceTypeCode Lookup code under resource types (`pages`, `asset`, …)
     * @param int $resourceId Target page / asset / table id
     */
    public function grantCallerRolesHolding(string $permissionName, string $resourceTypeCode, int $resourceId): void
    {
        $roleIds = $this->withoutAdminRole($this->callerRoleIdsHolding($permissionName));
        $this->grantFullAccess($roleIds, $resourceTypeCode, $resourceId);
    }

    /**
     * The caller's roles that hold `$permissionName` (admin role may still be
     * included; strip it with {@see withoutAdminRole()} before storing grants).
     *
     * @return list<int>
     */
    public function callerRoleIdsHolding(string $permissionName): array
    {
        $user = $this->userContextService->getCurrentUser();
        if ($user === null || $user->getId() === null) {
            return [];
        }

        /** @var list<array{id: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT r.id')
            ->from(Role::class, 'r')
            ->innerJoin('r.users', 'u')
            ->innerJoin('r.permissions', 'p')
            ->where('u.id = :userId')
            ->andWhere('p.name = :permission')
            ->setParameter('userId', (int) $user->getId())
            ->setParameter('permission', $permissionName)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Drop the admin role from a list of role ids. It manages every resource
     * through the short-circuit in DataAccessSecurityService and is never stored.
     *
     * @param list<int> $roleIds
     * @return list<int>
     */
    public function withoutAdminRole(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        if (!$this->adminRoleIdLoaded) {
            $admin = $this->entityManager->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
            $this->adminRoleId = $admin instanceof Role ? $admin->getId() : null;
            $this->adminRoleIdLoaded = true;
        }

        return array_values(array_filter($roleIds, fn (int $roleId): bool => $roleId !== $this->adminRoleId));
    }

    /**
     * Ensure each role has full CRUD on the resource. Existing grants are
     * raised to full access when needed; roles are not removed.
     *
     * @param list<int> $roleIds
     */
    public function grantFullAccess(array $roleIds, string $resourceTypeCode, int $resourceId): void
    {
        $roleIds = $this->withoutAdminRole(array_values(array_unique($roleIds)));
        if ($roleIds === []) {
            return;
        }

        $resourceTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceTypeCode);
        if ($resourceTypeId === null) {
            throw new ServiceException(
                sprintf('Unknown resource type "%s"', $resourceTypeCode),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        foreach ($roleIds as $roleId) {
            if (!$this->entityManager->find(Role::class, $roleId) instanceof Role) {
                throw new ServiceException('Unknown role id for creator grant', Response::HTTP_BAD_REQUEST);
            }
        }

        foreach ($roleIds as $roleId) {
            $existing = $this->entityManager->getRepository(RoleDataAccess::class)->findOneBy([
                'idRoles' => $roleId,
                'idResourceTypes' => $resourceTypeId,
                'resourceId' => $resourceId,
            ]);
            if ($existing instanceof RoleDataAccess && $existing->getCrudPermissions() === self::FULL_ACCESS) {
                continue;
            }

            // F14: grant on a brand-new resource the caller cannot yet hold.
            // Holdings (rule 4.6f) are enforced on admin grant replace paths.
            $this->dataAccess->addRolePermission(
                (int) $this->userContextService->getCurrentUser()?->getId(),
                $roleId,
                (object) [
                    'resource_type_id' => $resourceTypeId,
                    'resource_id' => $resourceId,
                    'crud_permissions' => self::FULL_ACCESS,
                ],
                false
            );
        }
    }
}
