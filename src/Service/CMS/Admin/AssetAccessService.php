<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Auth\UserContextService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two access questions for an asset.
 *
 * - **View** (the file, on the website/app): decided by the asset's groups. No
 *   groups = public, anonymous callers included; otherwise only members of
 *   ANY of its groups. The admin role views everything.
 * - **Manage** (the asset in the CMS: list, edit, delete): decided per asset
 *   by `role_data_access` (resource type `asset`, CRUD bits), like data
 *   tables. The admin role manages everything.
 */
class AssetAccessService extends BaseService
{
    public const FULL_ACCESS = DataAccessSecurityService::PERMISSION_CREATE
        | DataAccessSecurityService::PERMISSION_READ
        | DataAccessSecurityService::PERMISSION_UPDATE
        | DataAccessSecurityService::PERMISSION_DELETE;

    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly LookupService $lookupService,
    ) {
    }

    public function canView(Asset $asset): bool
    {
        if ($asset->getGroups()->isEmpty() || $this->isAdmin()) {
            return true;
        }

        $userId = $this->userId();
        $userGroupIds = $userId !== null ? $this->userRepository->getUserGroupIds($userId) : [];
        foreach ($asset->getGroups() as $group) {
            if (in_array($group->getId(), $userGroupIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function assertCanView(Asset $asset): void
    {
        if (!$this->canView($asset)) {
            $this->throwForbidden('You do not have access to this asset');
        }
    }

    public function assertCanManage(Asset $asset, int $permission): void
    {
        $userId = $this->userId();
        if ($userId === null || !$this->dataAccessSecurityService->hasPermission(
            $userId,
            LookupService::RESOURCE_TYPES_ASSET,
            (int) $asset->getId(),
            $permission
        )) {
            $this->throwForbidden('You do not have access to this asset');
        }
    }

    /**
     * Ids of the assets the caller may READ in the CMS, for scoping lists;
     * null for the admin role (no restriction).
     *
     * @return list<int>|null
     */
    public function manageableAssetIdsOrNull(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $userId = $this->userId();
        if ($userId === null) {
            return [];
        }

        $ids = [];
        foreach ($this->roleDataAccessRepository->getUserPermissionsForResources($userId, $this->resourceTypeId()) as $assetId => $crud) {
            if (($crud & DataAccessSecurityService::PERMISSION_READ) !== 0) {
                $ids[] = $assetId;
            }
        }

        return $ids;
    }

    /**
     * The roles that manage each asset, keyed by asset id.
     *
     * @param list<int> $assetIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function managingRoles(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        /** @var list<array{assetId: int|string, id: int|string, name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('rda.resourceId AS assetId', 'r.id', 'r.name')
            ->from(RoleDataAccess::class, 'rda')
            ->innerJoin('rda.role', 'r')
            ->where('rda.idResourceTypes = :type')
            ->andWhere('rda.resourceId IN (:ids)')
            ->andWhere('rda.crudPermissions > 0')
            ->setParameter('type', $this->resourceTypeId())
            ->setParameter('ids', $assetIds)
            ->orderBy('r.name')
            ->getQuery()
            ->getArrayResult();

        $byAsset = [];
        foreach ($rows as $row) {
            $byAsset[(int) $row['assetId']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return $byAsset;
    }

    /**
     * Replace the roles that manage an asset (each gets full access). `null`
     * means the caller's own roles, used for a new upload.
     *
     * @param list<int>|null $roleIds
     */
    public function setManagingRoles(Asset $asset, ?array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds ?? $this->currentUserRoleIds()));
        $type = $this->entityManager->getReference(Lookup::class, $this->resourceTypeId());
        assert($type instanceof Lookup);

        $touched = [];
        foreach ($this->roleDataAccessRepository->findBy(['resourceType' => $type, 'resourceId' => (int) $asset->getId()]) as $row) {
            $touched[] = (int) $row->getRole()->getId();
            $this->entityManager->remove($row);
        }
        $this->entityManager->flush();

        foreach ($roleIds as $roleId) {
            $role = $this->entityManager->find(Role::class, $roleId);
            if (!$role instanceof Role) {
                $this->throwBadRequest('Unknown role id in asset roles');
            }
            $row = new RoleDataAccess();
            $row->setRole($role);
            $row->setResourceType($type);
            $row->setResourceId((int) $asset->getId());
            $row->setCrudPermissions(self::FULL_ACCESS);
            $this->entityManager->persist($row);
            $touched[] = $roleId;
        }
        $this->entityManager->flush();

        foreach (array_unique($touched) as $roleId) {
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);
        }
    }

    /**
     * Resolve requested group ids to entities, rejecting unknown ids so an
     * asset can never be tagged with a group that does not exist.
     *
     * @param list<int> $groupIds
     * @return list<Group>
     */
    public function resolveGroups(array $groupIds): array
    {
        $groupIds = array_values(array_unique($groupIds));
        if ($groupIds === []) {
            return [];
        }

        /** @var list<Group> $groups */
        $groups = $this->entityManager->getRepository(Group::class)->findBy(['id' => $groupIds]);
        if (count($groups) !== count($groupIds)) {
            $this->throwBadRequest('Unknown group id in asset groups');
        }

        return $groups;
    }

    /**
     * Resolve group names to entities, silently skipping names that do not
     * exist on this instance (used by bundle import across instances).
     *
     * @param list<string> $names
     * @return list<Group>
     */
    public function groupsByName(array $names): array
    {
        if ($names === []) {
            return [];
        }

        /** @var list<Group> $groups */
        $groups = $this->entityManager->getRepository(Group::class)->findBy(['name' => array_values(array_unique($names))]);

        return $groups;
    }

    /**
     * @return list<int>
     */
    private function currentUserRoleIds(): array
    {
        $userId = $this->userId();
        if ($userId === null) {
            return [];
        }

        /** @var list<array{id: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(Role::class, 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function resourceTypeId(): int
    {
        return (int) $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_ASSET);
    }

    private function userId(): ?int
    {
        $userId = $this->userContextService->getCurrentUser()?->getId();

        return $userId !== null && $userId > 0 ? $userId : null;
    }

    private function isAdmin(): bool
    {
        $userId = $this->userId();

        return $userId !== null && $this->dataAccessSecurityService->userHasAdminRole($userId);
    }
}
