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
 * Admin plugin CRUD + lifecycle controller.
 *
 * Endpoints:
 *
 *   GET    /cms-api/v1/admin/plugins                          list plugins
 *   GET    /cms-api/v1/admin/plugins/{pluginId}               plugin detail
 *   POST   /cms-api/v1/admin/plugins                          request install
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/finalize-install
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/request-update
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/finalize-update
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/enable        enable
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/disable       disable
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/uninstall     uninstall
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/purge         purge (destructive)
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/repair        repair single
 *   POST   /cms-api/v1/admin/plugins/repair                    repair all
 */
final class AdminPluginController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /admin/plugins
     * @method GET
     */
    public function listPlugins(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess([
                'plugins' => $this->pluginAdminService->listPlugins(),
                'installMode' => $this->pluginAdminService->getInstallMode(),
                'safeMode' => $this->pluginAdminService->isSafeModeOn(),
            ], 'responses/admin/plugins/plugins_list');
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * Returns the plugins that are advertised by every enabled
     * `PluginSource` but are not yet installed in this host. Used by
     * the admin UI's "Available" tab to offer one-click registry
     * installs.
     *
     * @route /admin/plugins/available
     * @method GET
     */
    public function listAvailable(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess([
                'plugins' => $this->pluginAdminService->listAvailableFromRegistries(),
            ], 'responses/admin/plugins/plugins_list');
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}
     * @method GET
     */
    public function getPlugin(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->getPlugin($pluginId),
                'responses/admin/plugins/plugin_envelope'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins
     * @method POST
     */
    public function requestInstall(Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/plugins/install_plugin', $this->jsonSchemaValidationService);
            $manifest = $payload['manifest'];
            $registryEntry = $payload['registryEntry'] ?? null;
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->requestInstall($manifest, $registryEntry),
                'responses/admin/plugins/plugin_operation',
                Response::HTTP_ACCEPTED,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/finalize-install
     * @method POST
     */
    public function finalizeInstall(string $pluginId, Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/plugins/finalize_install', $this->jsonSchemaValidationService);
            $manifest = $payload['manifest'];
            if (($manifest['id'] ?? null) !== $pluginId) {
                return $this->responseFormatter->formatError('Manifest id does not match URL.', Response::HTTP_BAD_REQUEST);
            }
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->finalizeInstall((int) $payload['operationId'], $manifest),
                'responses/admin/plugins/plugin_operation'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/request-update
     * @method POST
     */
    public function requestUpdate(string $pluginId, Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/plugins/update_plugin', $this->jsonSchemaValidationService);
            $manifest = $payload['manifest'];
            if (($manifest['id'] ?? null) !== $pluginId) {
                return $this->responseFormatter->formatError('Manifest id does not match URL.', Response::HTTP_BAD_REQUEST);
            }
            $force = (bool) ($payload['forceMajor'] ?? false);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->requestUpdate($manifest, $force),
                'responses/admin/plugins/plugin_operation',
                Response::HTTP_ACCEPTED,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/finalize-update
     * @method POST
     */
    public function finalizeUpdate(string $pluginId, Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/plugins/finalize_install', $this->jsonSchemaValidationService);
            $manifest = $payload['manifest'];
            if (($manifest['id'] ?? null) !== $pluginId) {
                return $this->responseFormatter->formatError('Manifest id does not match URL.', Response::HTTP_BAD_REQUEST);
            }
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->finalizeUpdate((int) $payload['operationId'], $manifest),
                'responses/admin/plugins/plugin_operation'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/enable
     * @method POST
     */
    public function enable(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->enable($pluginId),
                'responses/admin/plugins/plugin_envelope'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/disable
     * @method POST
     */
    public function disable(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->disable($pluginId),
                'responses/admin/plugins/plugin_envelope'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/uninstall
     * @method POST
     */
    public function uninstall(string $pluginId): JsonResponse
    {
        try {
            $this->pluginAdminService->uninstall($pluginId);
            return $this->responseFormatter->formatSuccess(['pluginId' => $pluginId, 'status' => 'uninstalled']);
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/purge
     * @method POST
     */
    public function purge(string $pluginId, Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/plugins/purge_plugin', $this->jsonSchemaValidationService);
            $confirmed = (string) ($payload['confirmedPluginId'] ?? '');
            $this->pluginAdminService->purge($pluginId, $confirmed);
            return $this->responseFormatter->formatSuccess(['pluginId' => $pluginId, 'status' => 'purged']);
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/repair
     * @method POST
     */
    public function repairOne(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->pluginAdminService->repair($pluginId));
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/repair
     * @method POST
     */
    public function repairAll(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess($this->pluginAdminService->repair(null));
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
