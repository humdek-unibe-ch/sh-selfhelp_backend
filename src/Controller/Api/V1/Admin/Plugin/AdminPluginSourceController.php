<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Admin\Plugin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Plugin\Service\PluginAdminService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manage configured plugin registry sources.
 *
 *   GET    /admin/plugins/sources
 *   POST   /admin/plugins/sources
 *   PUT    /admin/plugins/sources/{sourceId}
 *   DELETE /admin/plugins/sources/{sourceId}
 */
final class AdminPluginSourceController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /admin/plugins/sources
     * @method GET
     */
    public function listSources(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->listSources(),
                'responses/admin/plugins/plugin_sources_list'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/sources
     * @method POST
     */
    public function createSource(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/plugins/create_source', $this->jsonSchemaValidationService);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->createSource($data),
                'responses/admin/plugins/plugin_source',
                Response::HTTP_CREATED,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/sources/{sourceId}
     * @method PUT
     */
    public function updateSource(int $sourceId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/plugins/update_source', $this->jsonSchemaValidationService);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->updateSource($sourceId, $data),
                'responses/admin/plugins/plugin_source'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/sources/{sourceId}
     * @method DELETE
     */
    public function deleteSource(int $sourceId): JsonResponse
    {
        try {
            $this->pluginAdminService->deleteSource($sourceId);
            return $this->responseFormatter->formatSuccess(['id' => $sourceId, 'status' => 'deleted']);
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
