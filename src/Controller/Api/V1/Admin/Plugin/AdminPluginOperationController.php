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
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin plugin operations controller — endpoints that act on a
 * `plugin_operations` row directly (rather than on a plugin).
 *
 *   POST   /cms-api/v1/admin/plugins/operations/{operationId}/cancel
 *
 * Mirrors the `selfhelp:plugin:cancel-operation` CLI command so
 * admins can clear a stuck operation row from the UI without
 * dropping to a shell. Wired by `Version20260523141331.php` to the
 * `admin.plugins.execute` permission (state-changing operation).
 */
final class AdminPluginOperationController extends AbstractController
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
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
            $status = $e->getCode();
            if (!is_int($status) || $status < Response::HTTP_BAD_REQUEST || $status >= 600) {
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
            return $this->responseFormatter->formatError($e->getMessage(), $status);
        }
    }
}
