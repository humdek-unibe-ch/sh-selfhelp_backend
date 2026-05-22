<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Admin\Plugin;

use App\Plugin\Health\PluginHealthService;
use App\Plugin\Service\PluginAdminService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 *   GET  /admin/plugins/{pluginId}/health
 *   GET  /admin/plugins/doctor
 *   POST /admin/plugins/safe-mode/enable
 *   POST /admin/plugins/safe-mode/disable
 */
final class AdminPluginHealthController extends AbstractController
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly PluginHealthService $healthService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * @route /admin/plugins/{pluginId}/health
     * @method GET
     */
    public function pluginHealth(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->healthService->runForPlugin($pluginId));
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/doctor
     * @method GET
     */
    public function doctor(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->healthService->runGlobalDoctor());
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/safe-mode/enable
     * @method POST
     */
    public function enableSafeMode(): JsonResponse
    {
        try {
            $this->pluginAdminService->safeModeEnable();
            return $this->responseFormatter->formatSuccess(['safeMode' => true]);
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/safe-mode/disable
     * @method POST
     */
    public function disableSafeMode(): JsonResponse
    {
        try {
            $this->pluginAdminService->safeModeDisable();
            return $this->responseFormatter->formatSuccess(['safeMode' => false]);
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    private function respondWithError(\Throwable $e): JsonResponse
    {
        $status = $e->getCode();
        if (!is_int($status) || $status < 100 || $status > 599) {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }
        return $this->responseFormatter->formatError($e->getMessage(), $status);
    }
}
