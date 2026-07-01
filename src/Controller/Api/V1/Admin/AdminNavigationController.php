<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\AdminNavigationService;
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
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/items/{item_id}/exclusions
     */
    public function addExclusion(int $item_id, Request $request): JsonResponse
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($request->getContent(), true) ?? [];
            $pageId = $data['page_id'] ?? $data['pageId'] ?? null;
            if (!is_int($pageId) && !is_numeric($pageId)) {
                return $this->responseFormatter->formatError('page_id is required', Response::HTTP_BAD_REQUEST);
            }

            $this->adminNavigationService->addExclusion($item_id, (int) $pageId);

            return $this->responseFormatter->formatSuccess(['added' => true], null, Response::HTTP_CREATED);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /cms-api/v1/admin/navigation/items/{item_id}/exclusions/{page_id}
     */
    public function removeExclusion(int $item_id, int $page_id): JsonResponse
    {
        try {
            $this->adminNavigationService->removeExclusion($item_id, $page_id);

            return $this->responseFormatter->formatSuccess(['removed' => true], null, Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /cms-api/v1/admin/navigation/items/{item_id}/convert-auto-children
     */
    public function convertAutoChildren(int $item_id, Request $request): JsonResponse
    {
        try {
            $languageId = $request->query->getInt('language_id') ?: 1;
            $created = $this->adminNavigationService->convertAutoChildrenToExplicit($item_id, $languageId);

            return $this->responseFormatter->formatSuccess(
                ['created_items' => $created],
                null,
                Response::HTTP_OK
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
