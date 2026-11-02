<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\AssetFolder;
use App\Entity\AssetFolderGroup;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Repository\AssetFolderGroupRepository;
use App\Repository\AssetFolderRepository;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Folder-level asset access control (closed-by-default).
 *
 * The admin role always has full (`manage`) access to every folder, mirroring
 * the page-ACL contract ({@see \App\Service\ACL\ACLService::hasAccess}). A
 * non-admin user has access to a folder ONLY if one of their groups is
 * explicitly granted on it — a folder with no grant for their groups (including
 * a folder with no rows at all) is not visible to them. `read` = list/get,
 * `manage` = also create/delete/import. New folders seed default grants on
 * first upload ({@see seedDefaultFolderAclsIfNew}).
 */
class AssetFolderAclService extends BaseService
{
    /**
     * Default ACL seeded onto a folder the first time an asset lands in it,
     * mirroring the page-create contract ({@see AdminPageService}): admin gets
     * manage, the subject + therapist groups get read.
     *
     * @var array<string, string> group name => access level
     */
    private const DEFAULT_FOLDER_ACL = [
        'admin' => AssetFolderGroup::ACCESS_MANAGE,
        'subject' => AssetFolderGroup::ACCESS_READ,
        'therapist' => AssetFolderGroup::ACCESS_READ,
    ];

    public function __construct(
        private readonly AssetFolderGroupRepository $aclRepository,
        private readonly AssetFolderRepository $folderRepository,
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly LookupService $lookupService,
    ) {
    }

    /**
     * Read the asset-folder ACL entries for one group.
     *
     * @return array{id_groups: int, acls: list<array{folder: string, access_level: string}>}
     */
    public function getGroupAssetAcls(int $groupId): array
    {
        $group = $this->entityManager->getRepository(Group::class)->find($groupId);
        if (!$group instanceof Group) {
            $this->throwNotFound('Group not found: ' . $groupId);
        }

        $acls = array_map(
            static fn (AssetFolderGroup $acl): array => [
                'folder' => $acl->getFolder(),
                'access_level' => $acl->getAccessLevel(),
            ],
            $this->aclRepository->findByGroup($groupId)
        );

        return [
            'id_groups' => $groupId,
            'acls' => $acls,
        ];
    }

    /**
     * Replace the full asset-folder ACL set for one group.
     *
     * Passing an empty `acls` list removes every folder grant for the group.
     * Closed-by-default: a folder left with no rows for any group is visible to
     * admin-role users only.
     *
     * @param list<array{folder: string, access_level: string}> $entries
     * @return array{id_groups: int, acls: list<array{folder: string, access_level: string}>}
     */
    public function updateGroupAssetAcls(int $groupId, array $entries): array
    {
        $group = $this->entityManager->getRepository(Group::class)->find($groupId);
        if (!$group instanceof Group) {
            $this->throwNotFound('Group not found: ' . $groupId);
        }

        $this->entityManager->beginTransaction();

        try {
            // Drop this group's existing rows, then re-add the requested set.
            foreach ($this->aclRepository->findByGroup($groupId) as $existing) {
                $this->entityManager->remove($existing);
            }
            $this->entityManager->flush();

            $seenFolders = [];
            foreach ($entries as $entry) {
                $folder = $this->normalizeFolder($entry['folder']);
                $accessLevel = $this->resolveAccessLevel($entry['access_level']);

                if (isset($seenFolders[$folder])) {
                    $this->throwBadRequest('Duplicate folder in group asset ACL: ' . $folder);
                }
                $seenFolders[$folder] = true;

                $acl = new AssetFolderGroup();
                $acl->setAssetFolder($this->findOrCreateFolder($folder));
                $acl->setGroup($group);
                $acl->setAccessLevelLookup($accessLevel);
                $this->entityManager->persist($acl);
            }

            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'assets_folders_groups',
                $groupId,
                false,
                'Group asset-folder ACL updated for group: ' . $groupId
            );

            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->invalidateCache();

        return $this->getGroupAssetAcls($groupId);
    }

    /**
     * Assert the current user may read the given folder, else 403.
     */
    public function assertCanRead(?string $folder): void
    {
        if ($this->userAccessLevel($folder) === null) {
            $this->throwForbidden('You do not have access to this asset folder');
        }
    }

    /**
     * Assert the current user may create/delete/import in the folder, else 403.
     */
    public function assertCanManage(?string $folder): void
    {
        if ($this->userAccessLevel($folder) !== AssetFolderGroup::ACCESS_MANAGE) {
            $this->throwForbidden('You do not have manage access to this asset folder');
        }
    }

    /**
     * The folders the current user is allowed to read, used to scope list
     * queries (closed-by-default: allow-list, not deny-list).
     *
     * Returns `null` for admin-role users, meaning "no restriction — every
     * folder". For a non-admin it returns the exact list of folders their groups
     * are granted on; an empty list means they can see no folders at all.
     *
     * @return list<string>|null
     */
    public function getVisibleFoldersOrNull(): ?array
    {
        // The admin role sees every folder (mirrors the page-ACL contract).
        if ($this->currentUserHasAdminRole()) {
            return null;
        }

        // Open-access folders are readable by everyone, including anonymous
        // callers, so they are always in the allow-list regardless of groups.
        $openFolders = $this->folderRepository->findOpenAccessFolders();

        $groupIds = $this->currentUserGroupIds();
        if ($groupIds === []) {
            return $openFolders;
        }

        return array_values(array_unique(array_merge(
            $openFolders,
            $this->aclRepository->findFoldersGrantedToGroups($groupIds)
        )));
    }

    /**
     * Resolve the current user's effective access level for a folder.
     *
     * Returns `manage`, `read`, or null (no access). Closed-by-default: a
     * non-admin user only has access to a folder their group is explicitly
     * granted on — a folder with no grant for any of their groups (including a
     * folder with no rows at all) resolves to null (no access). Admin-role users
     * always have full access.
     */
    private function userAccessLevel(?string $folder): ?string
    {
        // The admin role always has full access (mirrors the page-ACL contract).
        if ($this->currentUserHasAdminRole()) {
            return AssetFolderGroup::ACCESS_MANAGE;
        }

        $normalized = trim((string) ($folder ?? ''));
        if ($normalized === '') {
            // A null/empty folder carries no ACL rows; closed to non-admins.
            return null;
        }

        // Open-access folders grant read to everyone, including anonymous
        // callers, without consulting group grants.
        if ($this->folderRepository->isOpenAccess($normalized)) {
            return AssetFolderGroup::ACCESS_READ;
        }

        $levels = $this->aclRepository->findAccessLevelsForGroups($normalized, $this->currentUserGroupIds());
        if ($levels === []) {
            return null;
        }

        return in_array(AssetFolderGroup::ACCESS_MANAGE, $levels, true)
            ? AssetFolderGroup::ACCESS_MANAGE
            : AssetFolderGroup::ACCESS_READ;
    }

    /**
     * @return list<int>
     */
    private function currentUserGroupIds(): array
    {
        $user = $this->userContextService->getCurrentUser();
        if ($user === null) {
            return [];
        }

        $ids = [];
        foreach ($user->getGroups() as $group) {
            $id = $group->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Seed the default ACL rows onto a folder the FIRST time an asset lands in
     * it (folder has no rows yet), mirroring the page-create contract: admin =
     * manage, subject + therapist = read. Folders that already carry any rows
     * (author-configured) are left untouched.
     *
     * Runs inside the caller's transaction — it persists + flushes but never
     * begins/commits its own transaction. The caller must have flushed the
     * asset first so the folder string is final.
     */
    public function seedDefaultFolderAclsIfNew(string $folder): void
    {
        $folder = trim($folder);
        if ($folder === '') {
            return;
        }

        // Never overwrite an already-configured folder.
        if ($this->aclRepository->findByFolder($folder) !== []) {
            return;
        }

        $groupRepo = $this->entityManager->getRepository(Group::class);
        $folderEntity = $this->findOrCreateFolder($folder);
        $seeded = false;
        foreach (self::DEFAULT_FOLDER_ACL as $groupName => $accessLevel) {
            $group = $groupRepo->findOneBy(['name' => $groupName]);
            if (!$group instanceof Group) {
                // Missing a default group is non-fatal: skip it, keep the rest.
                continue;
            }

            $acl = new AssetFolderGroup();
            $acl->setAssetFolder($folderEntity);
            $acl->setGroup($group);
            $acl->setAccessLevelLookup($this->resolveAccessLevel($accessLevel));
            $this->entityManager->persist($acl);
            $seeded = true;
        }

        if ($seeded) {
            $this->entityManager->flush();
            $this->invalidateCache();
        }
    }

    /**
     * List every known folder with its open-access flag, for the admin UI.
     *
     * @return list<array{folder: string, is_open_access: bool}>
     */
    public function listFolders(): array
    {
        return array_map(
            static fn (AssetFolder $folder): array => [
                'folder' => $folder->getFolder(),
                'is_open_access' => $folder->isOpenAccess(),
            ],
            $this->folderRepository->findAllOrdered()
        );
    }

    /**
     * Toggle the open-access flag on a folder.
     *
     * Open access makes every asset in the folder readable by everyone,
     * including anonymous (not logged-in) callers, bypassing group grants. It
     * never confers `manage` — writing still requires an explicit group grant.
     *
     * @return array{folder: string, is_open_access: bool}
     */
    public function setFolderOpenAccess(string $folder, bool $isOpenAccess): array
    {
        $normalized = $this->normalizeFolder($folder);

        $this->entityManager->beginTransaction();

        try {
            $entity = $this->folderRepository->findOneByFolder($normalized);
            if (!$entity instanceof AssetFolder) {
                $this->throwNotFound('Asset folder not found: ' . $normalized);
            }

            $entity->setIsOpenAccess($isOpenAccess);
            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'assets_folders',
                $entity->getId(),
                false,
                'Asset folder open access ' . ($isOpenAccess ? 'enabled' : 'disabled') . ' for folder: ' . $normalized
            );

            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->invalidateCache();

        return [
            'folder' => $normalized,
            'is_open_access' => $isOpenAccess,
        ];
    }

    /**
     * Resolve a folder row by name, creating it (unflagged) if it does not
     * exist yet. Runs inside the caller's transaction: it persists + flushes so
     * the row can be referenced by FK immediately, but never begins/commits.
     */
    private function findOrCreateFolder(string $folder): AssetFolder
    {
        $existing = $this->folderRepository->findOneByFolder($folder);
        if ($existing instanceof AssetFolder) {
            return $existing;
        }

        $entity = new AssetFolder();
        $entity->setFolder($folder);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }

    /**
     * Whether the current user holds the admin role (which bypasses folder
     * ACLs entirely, mirroring the page-ACL contract). Anonymous users never do.
     */
    private function currentUserHasAdminRole(): bool
    {
        $userId = $this->userContextService->getCurrentUser()?->getId();

        return $userId !== null && $userId > 0
            && $this->dataAccessSecurityService->userHasAdminRole($userId);
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '') {
            $this->throwBadRequest('Folder is required');
        }

        return $folder;
    }

    /**
     * Resolve an access-level code to its `assetFolderAccessLevels` lookup row,
     * rejecting anything outside the catalog. Validation and resolution are one
     * step so an unknown level can never reach the database.
     */
    private function resolveAccessLevel(string $accessLevel): Lookup
    {
        $level = match ($accessLevel) {
            AssetFolderGroup::ACCESS_MANAGE => AssetFolderGroup::ACCESS_MANAGE,
            AssetFolderGroup::ACCESS_READ => AssetFolderGroup::ACCESS_READ,
            default => throw new \App\Exception\ServiceException(
                'Invalid access level: ' . $accessLevel,
                Response::HTTP_BAD_REQUEST
            ),
        };

        $lookup = $this->lookupService->findByTypeAndCode(
            LookupService::ASSET_FOLDER_ACCESS_LEVELS,
            $level
        );
        if (!$lookup instanceof Lookup) {
            // The catalog is seeded by migration, so a missing row is a broken
            // install rather than bad input.
            throw new \App\Exception\ServiceException(
                'Asset folder access level lookup missing: ' . $level,
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $lookup;
    }

    private function invalidateCache(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_ASSETS)
            ->invalidateAllListsInCategory();
    }
}
