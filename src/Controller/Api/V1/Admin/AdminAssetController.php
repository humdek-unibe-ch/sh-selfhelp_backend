<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AdminAssetService;
use App\Service\CMS\Admin\AssetExportImportService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Controller\Api\V1\Frontend\FormFileController;
use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Asset Controller
 *
 * Handles asset management operations for admin interface
 */
class AdminAssetController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminAssetService $adminAssetService,
        private readonly AssetExportImportService $assetExportImportService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService
    ) {
    }

    /**
     * Get all assets with pagination, search and an optional group filter
     *
     * @route /admin/assets
     * @method GET
     */
    public function getAllAssets(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $pageSize = max(1, min(1000, (int) $request->query->get('pageSize', 100)));
            $search = $request->query->get('search');
            $groupId = $request->query->get('group_id');
            $folderId = $request->query->get('folder_id');

            $result = $this->adminAssetService->getAllAssets(
                $page,
                $pageSize,
                is_string($search) && $search !== '' ? $search : null,
                is_string($groupId) && ctype_digit($groupId) ? (int) $groupId : null,
                is_string($folderId) && ctype_digit($folderId) ? (int) $folderId : null
            );
            return $this->responseFormatter->formatSuccess($result, 'responses/admin/assets/assets_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get asset by ID
     *
     * @route /admin/assets/{assetId}
     * @method GET
     */
    public function getAssetById(int $assetId): JsonResponse
    {
        try {
            $asset = $this->adminAssetService->getAssetById($assetId);
            return $this->responseFormatter->formatSuccess($asset, 'responses/admin/assets/asset_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Upload/Create new asset(s) - supports single or multiple files
     *
     * @route /admin/assets
     * @method POST
     */
    public function createAsset(Request $request): JsonResponse
    {
        try {
            $formData = $this->validateAssetFormData($request, 'requests/admin/create_assets');

            $files = $request->files->get('files', []);
            $singleFile = $request->files->get('file');

            // Handle both single file and multiple files
            if ($singleFile) {
                $files = [$singleFile];
            } elseif (!is_array($files)) {
                $files = [$files];
            }

            // Keep only valid uploaded files (drops empty/invalid entries)
            $uploadedFiles = array_values(array_filter(
                $files,
                static fn ($file): bool => $file instanceof UploadedFile
            ));

            if (empty($uploadedFiles)) {
                return $this->responseFormatter->formatError('At least one file is required', Response::HTTP_BAD_REQUEST);
            }

            $data = $this->assetData($formData);

            if (count($uploadedFiles) === 1) {
                $asset = $this->adminAssetService->createAsset($uploadedFiles[0], $data);
                return $this->responseFormatter->formatSuccess(
                    $asset,
                    'responses/admin/assets/asset_envelope',
                    Response::HTTP_CREATED
                );
            }

            $result = $this->adminAssetService->createMultipleAssets($uploadedFiles, $data);

            $statusCode = $result['failed_uploads'] > 0 ?
                Response::HTTP_PARTIAL_CONTENT :
                Response::HTTP_CREATED;

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/assets/assets_upload_envelope',
                $statusCode
            );
        } catch (RequestValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Edit an asset (name, alt, groups) and optionally replace its file under
     * the same uuid. Multipart, so it is a POST rather than a PUT.
     *
     * @route /admin/assets/{assetId}
     * @method POST
     */
    public function updateAsset(int $assetId, Request $request): JsonResponse
    {
        try {
            $formData = $this->validateAssetFormData($request, 'requests/admin/update_asset');
            $file = $request->files->get('file');

            $asset = $this->adminAssetService->updateAsset(
                $assetId,
                $this->assetData($formData),
                $file instanceof UploadedFile ? $file : null
            );

            return $this->responseFormatter->formatSuccess($asset, 'responses/admin/assets/asset_envelope');
        } catch (RequestValidationException $e) {
            return $this->validationError($e);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Add or remove groups on several assets at once.
     *
     * @route /admin/assets/bulk-groups
     * @method POST
     */
    public function bulkUpdateAssetGroups(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_update_asset_groups', $this->jsonSchemaValidationService);

            $result = $this->adminAssetService->bulkUpdateGroups(
                $this->toIntList($data['asset_ids'] ?? []),
                $this->toIntList($data['group_ids'] ?? []),
                is_string($data['mode'] ?? null) ? $data['mode'] : ''
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/assets/assets_bulk_groups_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Move several assets into one folder (`folder_id` null = the top level).
     *
     * @route /admin/assets/bulk-folder
     * @method POST
     */
    public function bulkMoveAssetsToFolder(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_move_assets', $this->jsonSchemaValidationService);
            $folderId = $data['folder_id'] ?? null;
            $result = $this->adminAssetService->bulkMoveToFolder($this->toIntList($data['asset_ids'] ?? []), is_int($folderId) ? $folderId : null);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/assets/assets_bulk_folder_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Delete several assets at once: all of them or none (404 for an unknown
     * id, 403 when one may not be deleted, and nothing is removed then).
     *
     * @route /admin/assets/bulk-delete
     * @method POST
     */
    public function bulkDeleteAssets(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_delete_assets', $this->jsonSchemaValidationService);
            $result = $this->adminAssetService->bulkDeleteAssets($this->toIntList($data['asset_ids'] ?? []));

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/assets/assets_bulk_delete_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * @route /admin/asset-folders
     * @method GET
     */
    public function getAssetFolders(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->adminAssetService->getFolders(), 'responses/admin/assets/asset_folders_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * @route /admin/asset-folders (POST) and /admin/asset-folders/{folderId} (PUT)
     */
    public function saveAssetFolder(Request $request, ?int $folderId = null): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/save_asset_folder', $this->jsonSchemaValidationService);
            $folders = $this->adminAssetService->saveFolder(
                $folderId,
                is_string($data['name'] ?? null) ? $data['name'] : '',
                is_int($data['parent_id'] ?? null) ? $data['parent_id'] : null
            );

            return $this->responseFormatter->formatSuccess($folders, 'responses/admin/assets/asset_folders_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * @route /admin/asset-folders/{folderId}
     * @method DELETE
     */
    public function deleteAssetFolder(int $folderId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->adminAssetService->deleteFolder($folderId), 'responses/admin/assets/asset_folders_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Delete asset
     *
     * @route /admin/assets/{assetId}
     * @method DELETE
     */
    public function deleteAsset(int $assetId): JsonResponse
    {
        try {
            $this->adminAssetService->deleteAsset($assetId);

            return $this->responseFormatter->formatSuccess(['deleted' => true]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Export assets as a ZIP bundle (files + manifest.json)
     *
     * @route /admin/assets/export
     * @method POST
     */
    public function exportAssets(Request $request): Response
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/export_assets', $this->jsonSchemaValidationService);

            $zipPath = $this->assetExportImportService->exportToZipFile($this->toIntList($data['asset_ids'] ?? []));

            // Sent from disk and deleted afterwards: a bundle can be gigabytes,
            // so it is never read into memory.
            return FormFileController::zipResponse($zipPath, 'asset_export.zip');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Import assets from an uploaded ZIP bundle. Never overwrites: an asset
     * whose uuid already exists is skipped.
     *
     * @route /admin/assets/import
     * @method POST
     */
    public function importAssets(Request $request): JsonResponse
    {
        try {
            $file = $request->files->get('file');
            if (!$file instanceof UploadedFile) {
                return $this->responseFormatter->formatError('A bundle file is required', Response::HTTP_BAD_REQUEST);
            }

            $result = $this->assetExportImportService->importFromZip($file);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/assets/assets_import_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Map validated form fields to the service's data keys. `group_ids` and
     * `role_ids` are comma-separated strings because multipart cannot send an
     * empty list: an empty string means "none" (no groups = public), an absent
     * field means "unchanged" (update) or the default (create: public, managed
     * by the uploader's roles that hold `admin.asset.create`). `folder_id` ''
     * = top level.
     *
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function assetData(array $formData): array
    {
        $data = [];
        foreach (['name', 'alt', 'folder_id'] as $key) {
            if (array_key_exists($key, $formData)) {
                $data[$key] = $formData[$key];
            }
        }

        foreach (['group_ids', 'role_ids'] as $key) {
            if (array_key_exists($key, $formData)) {
                $raw = is_string($formData[$key]) ? $formData[$key] : '';
                $data[$key] = $raw === '' ? [] : array_map('intval', explode(',', $raw));
            }
        }

        return $data;
    }

    /**
     * Validate the multipart (non-file) fields against a JSON schema.
     *
     * @return array<string, mixed>
     * @throws RequestValidationException
     */
    private function validateAssetFormData(Request $request, string $schema): array
    {
        $formData = [];

        // The uuid is the file name, so a sent `file_name` is not read.
        foreach (['name', 'alt', 'group_ids', 'role_ids', 'folder_id'] as $key) {
            if ($request->request->has($key)) {
                $formData[$key] = (string) $request->request->get($key);
            }
        }

        // File presence placeholders for schema validation
        $files = $request->files->get('files', []);
        $singleFile = $request->files->get('file');
        if ($singleFile) {
            $formData['file'] = 'binary_file_data';
        } elseif (!empty($files) && is_array($files)) {
            $formData['files'] = array_fill(0, count($files), 'binary_file_data');
        }

        try {
            $validationErrors = $this->jsonSchemaValidationService->validate(
                $this->convertToObject($formData),
                $schema
            );
        } catch (\TypeError $e) {
            throw new RequestValidationException(
                ['Schema validation error: ' . $e->getMessage()],
                $schema,
                $formData,
                'Schema validation failed'
            );
        }

        if (!empty($validationErrors)) {
            throw new RequestValidationException(
                $validationErrors,
                $schema,
                $formData,
                'Asset form validation failed'
            );
        }

        return $formData;
    }

    private function validationError(RequestValidationException $e): JsonResponse
    {
        return $this->responseFormatter->formatError(
            'Validation failed',
            Response::HTTP_BAD_REQUEST,
            null,
            $e->getValidationErrors()
        );
    }

    /**
     * @return list<int>
     */
    private function toIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_int')));
    }
}
