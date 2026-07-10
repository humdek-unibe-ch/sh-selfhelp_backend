<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\AdminNavigationService;
use App\Service\CMS\Admin\NavigationExportImportService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminNavigationController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminNavigationService $adminNavigationService,
        private readonly NavigationExportImportService $navigationExportImportService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * GET /cms-api/v1/admin/navigation
     */
    public function getOverview(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->getAdminOverview(),
                null,
                Response::HTTP_OK
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /cms-api/v1/admin/navigation/menus/{menu_key}/preview
     */
    public function previewMenu(string $menu_key, Request $request): JsonResponse
    {
        try {
            $languageId = $request->query->getInt('language_id') ?: 1;

            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->getMenuPreview($menu_key, $languageId),
                null,
                Response::HTTP_OK
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /cms-api/v1/admin/navigation/menus/{menu_key}
     */
    public function updateMenu(string $menu_key, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_navigation_menu', $this->jsonSchemaValidationService);
            /** @var array<string, mixed> $data */

            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->updateMenuDefinition($menu_key, $data),
                null,
                Response::HTTP_OK
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/menus/{menu_key}/items
     */
    public function createMenuItem(string $menu_key, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_navigation_menu_item', $this->jsonSchemaValidationService);
            /** @var array<string, mixed> $data */

            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->createMenuItem($menu_key, $data),
                null,
                Response::HTTP_CREATED
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /cms-api/v1/admin/navigation/items/{item_id}
     */
    public function updateMenuItem(int $item_id, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_navigation_menu_item', $this->jsonSchemaValidationService);
            /** @var array<string, mixed> $data */

            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->updateMenuItem($item_id, $data),
                null,
                Response::HTTP_OK
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /cms-api/v1/admin/navigation/items/{item_id}
     */
    public function deleteMenuItem(int $item_id): JsonResponse
    {
        try {
            $this->adminNavigationService->deleteMenuItem($item_id);

            return $this->responseFormatter->formatSuccess(['deleted' => true], null, Response::HTTP_OK);
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /cms-api/v1/admin/navigation/menus/{menu_key}/reorder
     */
    public function reorderMenuItems(string $menu_key, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/reorder_navigation_menu_items', $this->jsonSchemaValidationService);
            $order = $data['items'] ?? [];
            if (!is_array($order)) {
                $order = [];
            }
            /** @var list<array{item_id: int, position: int, parent_item_id?: int|null}> $typedOrder */
            $typedOrder = array_values(array_filter($order, 'is_array'));
            $this->adminNavigationService->reorderMenuItems($menu_key, $typedOrder);

            return $this->responseFormatter->formatSuccess(['reordered' => true], null, Response::HTTP_OK);
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /cms-api/v1/admin/navigation/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_navigation_settings', $this->jsonSchemaValidationService);
            /** @var array<string, mixed> $data */

            return $this->responseFormatter->formatSuccess(
                $this->adminNavigationService->updateSettings($data),
                null,
                Response::HTTP_OK
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/export
     */
    public function exportNavigation(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/export_navigation', $this->jsonSchemaValidationService);
            $options = is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : [];

            return $this->responseFormatter->formatSuccess(
                $this->navigationExportImportService->exportBundle($options),
                null,
                Response::HTTP_OK,
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/import/validate
     */
    public function validateImportNavigation(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/import_navigation', $this->jsonSchemaValidationService);
            $bundle = $this->toAssocArray($data['bundle'] ?? []);
            $options = is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : [];

            return $this->responseFormatter->formatSuccess(
                $this->navigationExportImportService->validateImport($bundle, $options),
                null,
                Response::HTTP_OK,
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/import
     */
    public function importNavigation(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/import_navigation', $this->jsonSchemaValidationService);
            $bundle = $this->toAssocArray($data['bundle'] ?? []);
            $options = is_array($data['options'] ?? null) ? $this->toAssocArray($data['options']) : [];
            $dryRun = $request->query->getBoolean('dry_run');
            if ($dryRun) {
                return $this->responseFormatter->formatSuccess(
                    $this->navigationExportImportService->validateImport($bundle, $options),
                    null,
                    Response::HTTP_OK,
                );
            }

            return $this->responseFormatter->formatSuccess(
                $this->navigationExportImportService->importBundle($bundle, $options),
                null,
                Response::HTTP_OK,
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 with field errors.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
