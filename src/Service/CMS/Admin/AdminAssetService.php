<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use App\Entity\AssetFolder;
use App\Entity\Group;
use App\Repository\AssetRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Security\DataAccessSecurityService;

use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin asset management. Uploads get a fresh uuid (their file name), so they
 * never overwrite; {@see updateAsset} replaces a file under the same uuid.
 */
class AdminAssetService extends BaseService
{
    private const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'svg', // Images
        'pdf', // Documents
        'mp4',
        'avi',
        'mov',
        'wmv',
        'flv',
        'webm', // Videos
        'mp3',
        'wav',
        'ogg', // Audio
        'css',
        'js', // Web files
        'zip',
        'rar',
        '7z', // Archives
        'json', // JSON files
        'txt',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx' // Office files
    ];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly AssetAccessService $accessService,
        private readonly AssetStorageService $storage,
    ) {
    }

    /**
     * Get all assets the caller may read, with pagination, search and an
     * optional group filter.
     *
     * @return array<string, mixed>
     */
    public function getAllAssets(int $page = 1, int $pageSize = 100, ?string $search = null, ?int $groupId = null, ?int $folderId = null): array
    {
        // null = admin (no restriction).
        $manageable = $this->accessService->manageableAssetIdsOrNull();

        // Per-caller scope, so users with different access never share a list.
        $aclScope = $manageable === null ? 'all' : md5(implode(',', $manageable));
        $cacheKey = "assets_list_{$page}_{$pageSize}_" . md5(($search ?? '') . '|' . ($groupId ?? '') . '|' . ($folderId ?? '')) . "_{$aclScope}";

        /** @var array<string, mixed> $result */
        $result = $this->cache
            ->withCategory(CacheService::CATEGORY_ASSETS)
            ->getList(
                $cacheKey,
                function () use ($page, $pageSize, $search, $groupId, $folderId, $manageable): array {
                    $result = $this->assetRepository->findAssetsWithPagination($page, $pageSize, $search, $groupId, $manageable, $folderId);
                    $roles = $this->accessService->managingRoles(array_map(static fn (Asset $asset): int => (int) $asset->getId(), $result['assets']));

                    return [
                        'assets' => array_map(fn (Asset $asset): array => $this->payload($asset, $roles[(int) $asset->getId()] ?? []), $result['assets']),
                        'pagination' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                            'total' => $result['total'],
                            'totalPages' => ceil($result['total'] / $pageSize)
                        ]
                    ];
                }
            );

        // What the caller may do with each asset is NOT cached: the cached page
        // is shared by every caller with the same manageable set, but viewing
        // depends on the caller's groups and updating/deleting on the grant bits.
        $result['assets'] = $this->withCallerRights($this->asArray($result['assets'] ?? null));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssetById(int $id): array
    {
        return $this->toArray($this->findManagedAsset($id, DataAccessSecurityService::PERMISSION_READ));
    }

    /**
     * Upload a new asset under a fresh uuid.
     *
     * `$data` keys: `name` (label; default the uploaded file name), `alt`,
     * `group_ids` (who may view it; none = public), `folder_id`, `role_ids`
     * (who may manage it; default the uploader's roles that hold
     * `admin.asset.create`), and — for bundle
     * import only — `uuid`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAsset(UploadedFile $file, array $data): array
    {
        $this->assertManagingRolesNotEmptied($data);
        $extension = $this->validateUpload($file);

        $uuid = $this->asStringOrNull($data['uuid'] ?? null) ?? $this->storage->generateUuid();
        if (!$this->storage->isUuid($uuid)) {
            $this->throwBadRequest('Invalid asset uuid');
        }
        if ($this->assetRepository->findOneByUuid($uuid) !== null) {
            throw new ServiceException('An asset with this uuid already exists', Response::HTTP_CONFLICT);
        }

        $groups = $this->accessService->resolveGroups($this->toIntList($data['group_ids'] ?? []));

        $asset = new Asset();
        $asset->setUuid($uuid);
        $asset->setAssetType($this->assetTypeFor($extension));
        $asset->setName($this->assetName($data['name'] ?? null, pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        $asset->setAlt($this->normalizeAlt($data['alt'] ?? null));
        $asset->setFolder($this->folderOrNull($data['folder_id'] ?? null));
        foreach ($groups as $group) {
            $asset->addGroup($group);
        }
        $this->recordFile($file, $asset);

        $staged = null;
        $swap = null;
        $this->entityManager->beginTransaction();
        try {
            $staged = $this->storage->stage($file, $uuid);

            $this->entityManager->persist($asset);
            $this->entityManager->flush();
            $this->accessService->setManagingRoles($asset, array_key_exists('role_ids', $data) ? $this->toIntList($data['role_ids']) : null);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'assets',
                $asset->getId(),
                $asset,
                'Asset created: ' . $asset->getName()
            );

            // The file goes in place last, so the commit is the only step left
            // that can fail; if it does the file goes again.
            $swap = $this->storage->swapIn($staged, $uuid);
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $swap?->undo();
            $this->storage->discardStaged($staged);
            throw $e;
        }
        $swap->keep();

        $this->invalidateAssetLists();

        return $this->toArray($asset);
    }

    /**
     * Create/Upload multiple assets
     *
     * @param array<int, UploadedFile> $files
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createMultipleAssets(array $files, array $data): array
    {
        // One refusal for the whole request, not one failed file after another.
        $this->assertManagingRolesNotEmptied($data);

        $results = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $results[] = $this->createAsset($file, $data);
            } catch (\Exception $e) {
                $errors[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'uploaded' => $results,
            'errors' => $errors,
            'total_files' => count($files),
            'successful_uploads' => count($results),
            'failed_uploads' => count($errors)
        ];
    }

    /**
     * Edit an asset. Only the keys present in `$data` change (`name`, `alt`,
     * `group_ids`, `folder_id`, `role_ids`); a new `$file` replaces the bytes under the SAME
     * uuid, so every page using the asset shows the new file.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateAsset(int $id, array $data, ?UploadedFile $file = null): array
    {
        $asset = $this->findManagedAsset($id, DataAccessSecurityService::PERMISSION_UPDATE);
        // Before the first change, so a refusal leaves the asset as it was.
        $this->assertManagingRolesNotEmptied($data);

        if (array_key_exists('name', $data)) {
            $asset->setName($this->assetName($data['name'], (string) $asset->getName()));
        }
        if (array_key_exists('alt', $data)) {
            // Not cached anywhere: page payloads read the asset's alt when they
            // are served (PageService::withAssetDefaultAlts), so an edit shows
            // at once without flushing cached sections.
            $asset->setAlt($this->normalizeAlt($data['alt']));
        }
        if (array_key_exists('group_ids', $data)) {
            $this->replaceGroups($asset, $this->accessService->resolveGroups($this->toIntList($data['group_ids'])));
        }
        if (array_key_exists('folder_id', $data)) {
            $asset->setFolder($this->folderOrNull($data['folder_id']));
        }

        $staged = null;
        $swap = null;
        $this->entityManager->beginTransaction();
        try {
            if ($file !== null) {
                $extension = $this->validateUpload($file);
                $asset->setAssetType($this->assetTypeFor($extension));
                $this->recordFile($file, $asset);
                $staged = $this->storage->stage($file, $asset->getUuid());
            }

            $asset->touch();
            $this->entityManager->flush();
            if (array_key_exists('role_ids', $data)) {
                $this->accessService->setManagingRoles($asset, $this->toIntList($data['role_ids']));
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'assets',
                $asset->getId(),
                $asset,
                'Asset updated: ' . $asset->getName() . ($file !== null ? ' (file replaced)' : '')
            );

            // The new bytes replace the old ones in one rename, last, so the
            // commit is the only step left that can fail; if it does the old
            // bytes come back. The row and the file never disagree.
            if ($staged !== null) {
                $swap = $this->storage->swapIn($staged, $asset->getUuid());
            }
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $swap?->undo();
            $this->storage->discardStaged($staged);
            throw $e;
        }
        $swap?->keep();

        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ASSET, $id);
        $this->invalidateAssetLists();

        return $this->toArray($asset);
    }

    /**
     * Add or remove the given groups on several assets at once.
     *
     * @param list<int> $assetIds
     * @param list<int> $groupIds
     * @return array{updated: int}
     */
    public function bulkUpdateGroups(array $assetIds, array $groupIds, string $mode): array
    {
        if (!in_array($mode, ['add', 'remove'], true)) {
            $this->throwBadRequest('Invalid mode: ' . $mode);
        }

        $groups = $this->accessService->resolveGroups($groupIds);
        $assets = $this->findAllOrFail($assetIds);

        // Every right is checked before anything changes (and before the
        // transaction, so a refusal's audit row is kept, not rolled back).
        foreach ($assets as $asset) {
            $this->accessService->assertCanManage($asset, DataAccessSecurityService::PERMISSION_UPDATE);
        }

        $this->entityManager->beginTransaction();
        try {
            foreach ($assets as $asset) {
                foreach ($groups as $group) {
                    $mode === 'add' ? $asset->addGroup($group) : $asset->removeGroup($group);
                }
                $asset->touch();
            }
            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'assets',
                null,
                false,
                sprintf('Bulk %s groups [%s] on assets [%s]', $mode, implode(',', $groupIds), implode(',', $assetIds))
            );

            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        foreach ($assets as $asset) {
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ASSET, (int) $asset->getId());
        }
        $this->invalidateAssetLists();

        return ['updated' => count($assets)];
    }

    /**
     * Move several assets into one folder (`$folderId` null/0 = the top level).
     *
     * @param list<int> $assetIds
     * @return array{moved: int}
     */
    public function bulkMoveToFolder(array $assetIds, ?int $folderId): array
    {
        $folder = $this->folderOrNull($folderId);
        $assets = $this->findAllOrFail($assetIds);

        // Every right is checked before the first asset moves.
        foreach ($assets as $asset) {
            $this->accessService->assertCanManage($asset, DataAccessSecurityService::PERMISSION_UPDATE);
        }
        foreach ($assets as $asset) {
            $asset->setFolder($folder);
            $asset->touch();
        }
        $this->entityManager->flush();
        $this->transactionService->logTransaction(
            LookupService::TRANSACTION_TYPES_UPDATE,
            LookupService::TRANSACTION_BY_BY_USER,
            'assets',
            null,
            false,
            sprintf('Moved assets [%s] to folder %s', implode(',', $assetIds), $folder?->getId() ?? 'top level')
        );
        $this->invalidateAssetLists();

        return ['moved' => count($assets)];
    }

    /**
     * Delete several assets as one step: all of them or none. Every asset must
     * exist and be deletable by the caller before the first one is removed, so
     * a refusal on the third id never leaves the first two deleted.
     *
     * @param list<int> $assetIds
     * @return array{deleted: int}
     */
    public function bulkDeleteAssets(array $assetIds): array
    {
        $assets = $this->findAllOrFail($assetIds);
        foreach ($assets as $asset) {
            $this->accessService->assertCanManage($asset, DataAccessSecurityService::PERMISSION_DELETE);
        }

        $this->removeAssets($assets);

        return ['deleted' => count($assets)];
    }

    public function deleteAsset(int $id): bool
    {
        $this->removeAssets([$this->findManagedAsset($id, DataAccessSecurityService::PERMISSION_DELETE)]);

        return true;
    }

    /**
     * Delete assets whose delete right has been checked: the rows, their
     * grants and their log entries in ONE transaction, and the files only
     * after it committed. A failed commit therefore leaves every row and every
     * file; the other order would leave rows without files. A file that cannot
     * be removed afterwards is only an unreachable orphan.
     *
     * @param list<Asset> $assets
     */
    private function removeAssets(array $assets): void
    {
        $removed = [];

        $this->entityManager->beginTransaction();
        try {
            foreach ($assets as $asset) {
                $removed[(int) $asset->getId()] = $asset->getUuid();

                // Log before the delete: the entity is still readable.
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_DELETE,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'assets',
                    $asset->getId(),
                    $asset,
                    'Asset deleted: ' . $asset->getName()
                );

                // role_data_access has no FK to the asset: drop its grants first.
                $this->accessService->setManagingRoles($asset, []);
                $this->entityManager->remove($asset);
            }
            $this->entityManager->flush();

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        foreach ($removed as $id => $uuid) {
            $this->storage->delete($uuid);
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_ASSET, $id);
        }
        $this->invalidateAssetLists();
    }

    /**
     * The assets with these ids; every id must exist, otherwise nothing is
     * returned and the request is a 404 (so a bulk action never does half).
     *
     * @param list<int> $assetIds
     * @return list<Asset>
     */
    private function findAllOrFail(array $assetIds): array
    {
        $assets = $this->assetRepository->findByIds($assetIds);
        if (count($assets) !== count(array_unique($assetIds))) {
            $this->throwNotFound('Asset not found');
        }

        return $assets;
    }

    /**
     * The API shape of an asset for the CALLER: the cache-safe payload plus
     * what the caller may do with it (`can_view`, `can_update`, `can_delete`).
     *
     * @param list<array{id: int, name: string}>|null $roles null = look them up
     * @return array<string, mixed>
     */
    public function toArray(Asset $asset, ?array $roles = null): array
    {
        return $this->withCallerRights([$this->payload($asset, $roles)])[0];
    }

    /**
     * Add the caller's rights to asset payloads. Never put the result in a
     * shared cache: it differs per caller.
     *
     * @param array<mixed> $payloads asset payloads as built by {@see payload()}
     * @return list<array<string, mixed>>
     */
    private function withCallerRights(array $payloads): array
    {
        $assets = array_map(fn (mixed $payload): array => $this->asAssocArray($payload), array_values($payloads));

        $groupIdsByAsset = [];
        foreach ($assets as $asset) {
            $groupIdsByAsset[$this->asInt($asset['id'] ?? 0)] = array_values(array_map(
                fn (mixed $group): int => $this->asInt($this->asAssocArray($group)['id'] ?? 0),
                $this->asArray($asset['groups'] ?? null)
            ));
        }
        $rights = $this->accessService->rightsFor($groupIdsByAsset);

        return array_map(
            fn (array $asset): array => $asset + ($rights[$this->asInt($asset['id'] ?? 0)] ?? ['can_view' => false, 'can_update' => false, 'can_delete' => false]),
            $assets
        );
    }

    /**
     * The cache-safe API shape of an asset: everything that is the same for
     * every caller. Rights are added per caller by {@see withCallerRights()}.
     *
     * @param list<array{id: int, name: string}>|null $roles null = look them up
     * @return array<string, mixed>
     */
    private function payload(Asset $asset, ?array $roles = null): array
    {
        return [
            'id' => $asset->getId(),
            'uuid' => $asset->getUuid(),
            'asset_type' => $asset->getAssetType()->getLookupValue(),
            'name' => $asset->getName(),
            'url' => $this->storage->publicUrl($asset->getUuid()),
            'mime_type' => $asset->getMimeType(),
            'size' => $asset->getSize(),
            'alt' => $asset->getAlt(),
            'folder_id' => $asset->getFolder()?->getId(),
            'roles' => $roles ?? $this->accessService->managingRoles([(int) $asset->getId()])[(int) $asset->getId()] ?? [],
            'groups' => array_values(array_map(
                static fn (Group $group): array => ['id' => $group->getId(), 'name' => $group->getName()],
                $asset->getGroups()->toArray()
            )),
            'created_at' => $asset->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The asset folders as a flat list; clients build the tree from `parent_id`.
     * `asset_count` counts the assets directly in the folder that the caller
     * may manage.
     *
     * @return list<array{id: int|null, name: string, parent_id: int|null, asset_count: int}>
     */
    public function getFolders(): array
    {
        /** @var list<AssetFolder> $folders */
        $folders = $this->entityManager->getRepository(AssetFolder::class)->findBy([], ['name' => 'ASC']);
        $counts = $this->assetRepository->countByFolder($this->accessService->manageableAssetIdsOrNull());

        return array_map(static fn (AssetFolder $folder): array => [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
            'parent_id' => $folder->getParent()?->getId(),
            'asset_count' => $counts[(int) $folder->getId()] ?? 0,
        ], $folders);
    }

    /**
     * Create (`$id` null) or rename/move a folder; returns the folder list.
     *
     * @return list<array{id: int|null, name: string, parent_id: int|null, asset_count: int}>
     */
    public function saveFolder(?int $id, string $name, ?int $parentId): array
    {
        $folder = $id === null ? new AssetFolder() : $this->folderOrNull($id);
        if (!$folder instanceof AssetFolder) {
            $this->throwNotFound('Folder not found');
        }
        $parent = $this->folderOrNull($parentId);
        // A folder cannot move into itself or one of its own subfolders.
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($id !== null && $ancestor->getId() === $id) {
                $this->throwBadRequest('A folder cannot be moved into itself');
            }
        }

        $folder->setName(mb_substr(trim($name), 0, 100));
        $folder->setParent($parent);
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        $this->invalidateAssetLists();

        return $this->getFolders();
    }

    /**
     * The folder at the end of a name path (`['Courses', 'Week 1']`), created
     * where it does not exist yet; an empty path is the top level (null).
     * Bundle import uses it: a bundle carries the folder as names because ids
     * differ between instances. An existing folder of that name under the same
     * parent is reused, never duplicated.
     *
     * @param list<string> $names folder names from the top level down
     */
    public function ensureFolderPath(array $names): ?AssetFolder
    {
        $parent = null;
        foreach ($names as $name) {
            $name = mb_substr(trim($name), 0, 100);
            if ($name === '') {
                $this->throwBadRequest('Invalid folder path');
            }

            $folder = $this->entityManager->getRepository(AssetFolder::class)->findOneBy(['name' => $name, 'parent' => $parent], ['id' => 'ASC']);
            if (!$folder instanceof AssetFolder) {
                $folder = (new AssetFolder())->setName($name)->setParent($parent);
                $this->entityManager->persist($folder);
                $this->entityManager->flush();
            }
            $parent = $folder;
        }

        return $parent;
    }

    /**
     * Delete a folder and its subfolders; their assets move to the top level.
     *
     * @return list<array{id: int|null, name: string, parent_id: int|null, asset_count: int}>
     */
    public function deleteFolder(int $id): array
    {
        $folder = $this->folderOrNull($id);
        if (!$folder instanceof AssetFolder) {
            $this->throwNotFound('Folder not found');
        }
        $this->entityManager->remove($folder);
        $this->entityManager->flush();
        $this->invalidateAssetLists();

        return $this->getFolders();
    }

    private function folderOrNull(mixed $id): ?AssetFolder
    {
        // null, '' and 0 mean the top level.
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
        if ($id === 0) {
            return null;
        }
        $folder = $this->entityManager->find(AssetFolder::class, $id);
        if (!$folder instanceof AssetFolder) {
            $this->throwBadRequest('Unknown asset folder');
        }

        return $folder;
    }

    private function findManagedAsset(int $id, int $permission): Asset
    {
        $asset = $this->assetRepository->find($id);
        if (!$asset instanceof Asset) {
            throw new ServiceException('Asset not found', Response::HTTP_NOT_FOUND);
        }

        $this->accessService->assertCanManage($asset, $permission);

        return $asset;
    }

    /**
     * @return string The lower-cased file extension
     */
    private function validateUpload(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new ServiceException('File upload failed: ' . $file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ServiceException(
                'File type not allowed. Allowed types: ' . implode(', ', self::ALLOWED_EXTENSIONS),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $extension;
    }

    /**
     * Record the upload's mime type and size on the asset. Read before the
     * file is staged (moved), while the temp file still exists.
     */
    private function recordFile(UploadedFile $file, Asset $asset): void
    {
        $asset->setMimeType($file->getMimeType() ?? $file->getClientMimeType());
        $size = $file->getSize();
        $asset->setSize($size === false ? null : $size);
    }

    private function assetTypeFor(string $extension): \App\Entity\Lookup
    {
        $assetType = $this->lookupService->findByTypeAndCode(
            LookupService::ASSET_TYPES,
            $extension === 'css' ? LookupService::ASSET_TYPES_CSS : LookupService::ASSET_TYPES_ASSET
        );
        if (!$assetType) {
            throw new ServiceException('Asset type lookup not found', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $assetType;
    }

    /**
     * An empty name falls back to `$fallback` (the uploaded or current name).
     */
    private function assetName(mixed $requested, string $fallback): ?string
    {
        $name = trim($this->asStringOrNull($requested) ?? '');
        $name = $name !== '' ? $name : trim($fallback);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    private function normalizeAlt(mixed $alt): ?string
    {
        $alt = trim($this->asStringOrNull($alt) ?? '');

        return $alt === '' ? null : mb_substr($alt, 0, 500);
    }

    /**
     * @param list<Group> $groups
     */
    private function replaceGroups(Asset $asset, array $groups): void
    {
        foreach ($asset->getGroups()->toArray() as $existing) {
            $asset->removeGroup($existing);
        }
        foreach ($groups as $group) {
            $asset->addGroup($group);
        }
    }

    /**
     * @return list<int>
     */
    private function toIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }

    /**
     * A `role_ids` that is present and empty would leave the asset to the admin
     * role alone (F63): only an admin may send that. An absent key is the
     * default on upload and "unchanged" on edit, so it is not checked.
     *
     * @param array<string, mixed> $data
     */
    private function assertManagingRolesNotEmptied(array $data): void
    {
        if (array_key_exists('role_ids', $data) && $this->toIntList($data['role_ids']) === []) {
            $this->accessService->assertMayLeaveWithoutManagingRoles();
        }
    }

    private function invalidateAssetLists(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_ASSETS)
            ->invalidateAllListsInCategory();
    }
}
