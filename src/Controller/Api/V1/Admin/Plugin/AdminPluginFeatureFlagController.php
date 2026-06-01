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
 *   GET  /admin/plugins/{pluginId}/feature-flags
 *   POST /admin/plugins/{pluginId}/feature-flags
 */
final class AdminPluginFeatureFlagController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /admin/plugins/{pluginId}/feature-flags
     * @method GET
     */
    public function listFlags(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->listFeatureFlags($pluginId),
                'responses/admin/plugins/plugin_feature_flags'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/feature-flags
     * @method POST
     */
    public function setFlag(string $pluginId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/plugins/set_feature_flag', $this->jsonSchemaValidationService);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->setFeatureFlag($pluginId, $this->toAssocArray($data)),
                'responses/admin/plugins/plugin_feature_flags'
            );
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
