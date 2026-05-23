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
 *   GET    /cms-api/v1/admin/plugins/available                discover from sources
 *   GET    /cms-api/v1/admin/plugins/{pluginId}               plugin detail
 *   POST   /cms-api/v1/admin/plugins/install                  unified install (registry|url|paste|archive)
 *   POST   /cms-api/v1/admin/plugins/inspect-archive          preview a .shplugin upload
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/update        unified update
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/enable        enable
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/disable       disable
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/uninstall     uninstall
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/purge         purge (destructive)
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/repair        repair single
 *   POST   /cms-api/v1/admin/plugins/repair                   repair all
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
     * Lists installed plugins that have a strictly-newer entry in any
     * enabled `PluginSource`. Powers the admin "Updates" tab — one row
     * per upgradeable plugin with `installedVersion`,
     * `availableVersion`, and the resolved registry entry for one-click
     * update dispatch.
     *
     * @route /admin/plugins/updates
     * @method GET
     */
    public function listUpdates(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess([
                'updates' => $this->pluginAdminService->listAvailableUpdates(),
            ]);
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
     * Unified install endpoint. Accepts JSON for `source ∈
     * {registry, url, paste}` and `multipart/form-data` for `source=archive`
     * (with a `.shplugin` file under `archive`). Dispatches a single
     * `InstallPluginMessage` regardless of source.
     *
     * @route /admin/plugins/install
     * @method POST
     */
    public function install(Request $request): JsonResponse
    {
        try {
            [$input, $archive] = $this->extractInstallInput($request);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->install($input, $archive),
                'responses/admin/plugins/plugin_operation',
                Response::HTTP_ACCEPTED,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * Pre-install inspection for `.shplugin` uploads. Extracts the
     * archive, verifies its signature + checksums, and returns the
     * manifest + compatibility report for the UI preview. Does NOT
     * dispatch an install operation.
     *
     * @route /admin/plugins/inspect-archive
     * @method POST
     */
    public function inspectArchive(Request $request): JsonResponse
    {
        try {
            $archive = $request->files->get('archive');
            if (!$archive instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                return $this->responseFormatter->formatError(
                    'inspect-archive requires a multipart `archive` file part.',
                    Response::HTTP_BAD_REQUEST,
                );
            }
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->inspectArchive($archive),
                null,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * Unified update endpoint. Same shape as `install`.
     *
     * @route /admin/plugins/{pluginId}/update
     * @method POST
     */
    public function update(string $pluginId, Request $request): JsonResponse
    {
        try {
            [$input, $archive] = $this->extractInstallInput($request);
            // Lock the URL-pinned plugin id against the resolved manifest in the service layer.
            $input['expectedPluginId'] = $pluginId;
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->update($input, $archive),
                'responses/admin/plugins/plugin_operation',
                Response::HTTP_ACCEPTED,
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @return array{0: array<string,mixed>, 1: \Symfony\Component\HttpFoundation\File\UploadedFile|null}
     */
    private function extractInstallInput(Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
        if ($isMultipart) {
            $source = $request->request->get('source', 'archive');
            $forceMajor = filter_var($request->request->get('forceMajor', false), FILTER_VALIDATE_BOOLEAN);
            $backupBefore = filter_var($request->request->get('backupBefore', false), FILTER_VALIDATE_BOOLEAN);
            $archive = $request->files->get('archive');
            return [
                [
                    'source' => $source,
                    'forceMajor' => $forceMajor,
                    'backupBefore' => $backupBefore,
                ],
                $archive instanceof \Symfony\Component\HttpFoundation\File\UploadedFile ? $archive : null,
            ];
        }
        $raw = (string) $request->getContent();
        $payload = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        return [$payload, null];
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
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->uninstall($pluginId),
                'responses/admin/plugins/plugin_operation',
                Response::HTTP_ACCEPTED,
            );
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
