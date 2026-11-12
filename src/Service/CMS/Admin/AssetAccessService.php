<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use App\Entity\Group;
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

    /** Route permission that lets a role upload assets (and so manage its own uploads by default). */
    private const CREATE_PERMISSION = 'admin.asset.create';

    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly AdminDataAccessService $dataAccess,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly LookupService $lookupService,
    ) {
    }

    public function canView(Asset $asset): bool
    {
        $groupIds = array_values(array_map(static fn (Group $group): int => (int) $group->getId(), $asset->getGroups()->toArray()));
        if ($groupIds === [] || $this->isAdmin()) {
            return true;
        }

        return self::sharesAGroup($groupIds, $this->callerGroupIds());
    }

    /**
     * What the caller may do with each given asset, so the CMS can draw only
     * the actions the server would accept. One query for the grants and one
     * for the caller's groups, whatever the number of assets. It reads the
     * grants directly (not through DataAccessSecurityService::hasPermission,
     * which writes an audit row per check) and applies the same rules as
     * {@see canView()} and {@see assertCanManage()}.
     *
     * @param array<int, list<int>> $groupIdsByAsset asset id => ids of the groups that may view it
     * @return array<int, array{can_view: bool, can_update: bool, can_delete: bool}>
     */
    public function rightsFor(array $groupIdsByAsset): array
    {
        if ($groupIdsByAsset === []) {
            return [];
        }

        $isAdmin = $this->isAdmin();
        $userId = $this->userId();
        $grants = [];
        $callerGroupIds = [];
        if (!$isAdmin && $userId !== null) {
            $grants = $this->roleDataAccessRepository->getUserPermissionsForResources($userId, $this->resourceTypeId(), array_keys($groupIdsByAsset));
            $callerGroupIds = $this->callerGroupIds();
        }

        return self::decideRights($groupIdsByAsset, $isAdmin, $grants, $callerGroupIds);
    }

    /**
     * The decision behind {@see rightsFor()}, apart from where its facts come
     * from: the admin role may do everything; anyone may view a public asset
     * or one of a group they are in; updating and deleting need the UPDATE and
     * DELETE bits of a grant on that asset.
     *
     * @param array<int, list<int>> $groupIdsByAsset asset id => ids of the groups that may view it
     * @param array<int, int> $grantsByAsset asset id => CRUD bits the caller's roles hold on it
     * @param list<int> $callerGroupIds
     * @return array<int, array{can_view: bool, can_update: bool, can_delete: bool}>
     */
    public static function decideRights(array $groupIdsByAsset, bool $isAdmin, array $grantsByAsset, array $callerGroupIds): array
    {
        $rights = [];
        foreach ($groupIdsByAsset as $assetId => $groupIds) {
            $crud = $grantsByAsset[$assetId] ?? 0;
            $rights[$assetId] = [
                'can_view' => $isAdmin || $groupIds === [] || self::sharesAGroup($groupIds, $callerGroupIds),
                'can_update' => $isAdmin || ($crud & DataAccessSecurityService::PERMISSION_UPDATE) !== 0,
                'can_delete' => $isAdmin || ($crud & DataAccessSecurityService::PERMISSION_DELETE) !== 0,
            ];
        }

        return $rights;
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
     * An explicit, empty list of managing roles leaves the asset to the admin
     * role alone, so a caller who is not admin would lose the asset they are
     * uploading or editing (F63). Only an admin may do that on purpose; for
     * everyone else the request is refused (400), before anything changes.
     */
    public function assertMayLeaveWithoutManagingRoles(): void
    {
        if (!$this->isAdmin()) {
            $this->throwBadRequest('An asset needs at least one managing role; only an admin may leave it with none');
        }
    }

    /**
     * Replace the roles that manage an asset (each gets full access). `null`
     * means the default for a new upload: the uploader's roles that hold
     * `admin.asset.create` (the same rule as the creator's grants on a new
     * page; the admin role manages every asset anyway).
     *
     * Grants are written through {@see AdminDataAccessService}, the one place
     * that writes `role_data_access`, so each change is logged and the touched
     * roles' permission caches are dropped exactly as on the role screens.
     *
     * @param list<int>|null $roleIds
     */
    public function setManagingRoles(Asset $asset, ?array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds ?? $this->defaultManagingRoleIds()));
        foreach ($roleIds as $roleId) {
            if (!$this->entityManager->find(Role::class, $roleId) instanceof Role) {
                $this->throwBadRequest('Unknown role id in asset roles');
            }
        }

        $assetId = (int) $asset->getId();
        $typeId = $this->resourceTypeId();

        $current = [];
        foreach ($this->roleDataAccessRepository->findBy(['idResourceTypes' => $typeId, 'resourceId' => $assetId]) as $row) {
            $current[(int) $row->getRole()->getId()] = $row;
        }

        foreach ($current as $roleId => $row) {
            if (!in_array($roleId, $roleIds, true)) {
                $this->dataAccess->deleteRolePermission($roleId, (int) $row->getId());
            }
        }

        foreach ($roleIds as $roleId) {
            if (isset($current[$roleId]) && $current[$roleId]->getCrudPermissions() === self::FULL_ACCESS) {
                continue;
            }
            $this->dataAccess->addRolePermission($roleId, (object) [
                'resource_type_id' => $typeId,
                'resource_id' => $assetId,
                'crud_permissions' => self::FULL_ACCESS,
            ]);
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
     * Resolve group names to entities. Names that do not exist on this instance
     * are simply absent from the result; the caller decides what that means
     * (bundle import refuses the asset rather than publish it).
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
     * The caller's roles that hold the permission to create assets: who
     * manages an upload when the request names no roles.
     *
     * @return list<int>
     */
    private function defaultManagingRoleIds(): array
    {
        $userId = $this->userId();
        if ($userId === null) {
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
            ->setParameter('userId', $userId)
            ->setParameter('permission', self::CREATE_PERMISSION)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function resourceTypeId(): int
    {
        return (int) $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_ASSET);
    }

    /**
     * Ids of the groups the caller belongs to (none for an anonymous caller).
     *
     * @return list<int>
     */
    private function callerGroupIds(): array
    {
        $userId = $this->userId();

        return $userId !== null ? $this->userRepository->getUserGroupIds($userId) : [];
    }

    /**
     * The view rule for a non-public asset: the caller is in ANY of its groups.
     *
     * @param list<int> $assetGroupIds
     * @param list<int> $callerGroupIds
     */
    private static function sharesAGroup(array $assetGroupIds, array $callerGroupIds): bool
    {
        return array_intersect($assetGroupIds, $callerGroupIds) !== [];
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
