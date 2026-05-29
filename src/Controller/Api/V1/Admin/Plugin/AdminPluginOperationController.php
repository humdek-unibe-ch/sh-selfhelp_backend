<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Admin\Plugin;

use App\Plugin\Service\PluginAdminService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin plugin operations controller — endpoints that act on a
 * `plugin_operations` row directly (rather than on a plugin).
 *
 *   GET    /cms-api/v1/admin/plugins/operations
 *   GET    /cms-api/v1/admin/plugins/operations/{operationId}
 *   POST   /cms-api/v1/admin/plugins/operations/{operationId}/rollback
 *   POST   /cms-api/v1/admin/plugins/operations/{operationId}/cancel
 *
 * The list/get endpoints power the admin "Operation history" tab.
 * `rollback` re-applies the operation's snapshot to undo an install
 * or update. `cancel` clears a stuck operation row (mirrors the
 * `selfhelp:plugin:cancel-operation` CLI command).
 */
final class AdminPluginOperationController extends AbstractController
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * @route /admin/plugins/operations
     * @method GET
     */
    public function listOperations(Request $request): JsonResponse
    {
        try {
            $pluginId = $request->query->get('pluginId');
            $limit = (int) $request->query->get('limit', 100);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->listOperations(
                    is_string($pluginId) && $pluginId !== '' ? $pluginId : null,
                    $limit,
                ),
                'responses/admin/plugins/plugin_operations_list',
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/operations/{operationId}
     * @method GET
     */
    public function getOperation(int $operationId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->getOperation($operationId),
                'responses/admin/plugins/plugin_operation',
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/operations/{operationId}/rollback
     * @method POST
     */
    public function rollback(int $operationId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->rollback($operationId),
                'responses/admin/plugins/plugin_operation',
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/operations/{operationId}/cancel
     * @method POST
     */
    public function cancel(int $operationId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->cancelOperation($operationId),
                'responses/admin/plugins/plugin_operation',
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    private function respondWithError(\Throwable $e): JsonResponse
    {
        $status = $e->getCode();
        if (!is_int($status) || $status < Response::HTTP_BAD_REQUEST || $status >= 600) {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }
        return $this->responseFormatter->formatError($e->getMessage(), $status);
    }
}
