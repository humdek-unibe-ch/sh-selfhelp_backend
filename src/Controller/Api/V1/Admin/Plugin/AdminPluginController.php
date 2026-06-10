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
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/pin           pin
 *   POST   /cms-api/v1/admin/plugins/{pluginId}/unpin         unpin
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
     * For JSON requests the body is validated against
     * `requests/admin/plugins/install.json` so malformed payloads are
     * rejected with a structured 400 instead of crashing in the
     * resolver. Multipart requests skip JSON validation — the resolver
     * itself surfaces missing-archive / bad-source errors.
     *
     * @route /admin/plugins/install
     * @method POST
     */
    public function install(Request $request): JsonResponse
    {
        try {
            [$input, $archive] = $this->extractInstallInput($request, validate: true);
            $payload = $this->pluginAdminService->install($input, $archive);
            // The service may short-circuit with installAction=already_installed
            // when the requested version is already on disk. That's a no-op,
            // not a queued operation, so 200 OK is more accurate than the
            // default 202 ACCEPTED used for install/update dispatches.
            $status = (isset($payload['installAction']) && $payload['installAction'] === 'already_installed')
                ? Response::HTTP_OK
                : Response::HTTP_ACCEPTED;
            return $this->responseFormatter->formatSuccess(
                $payload,
                'responses/admin/plugins/plugin_operation',
                $status,
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
     * Optional multipart fields:
     *
     *   - `trustedKeyId`     — keyId the operator wants to add as a
     *                          per-request trusted key.
     *   - `trustedKeyBase64` — base64-encoded 32-byte Ed25519 public
     *                          key matching that keyId.
     *
     * Both must be supplied together or both omitted. The trust
     * override is per-request only; the env-resolved trusted-keys set
     * is left untouched and env keys win on duplicate keyIds. The
     * trust-helper UI in `Admin → Plugins → Install plugin → Upload
     * .shplugin` populates these fields when the previous inspect
     * call returned `signature.unknownKey`.
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
            $trustOverride = $this->extractTrustedKeyOverride($request);
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->inspectArchive($archive, $trustOverride),
                null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * Reads + validates the optional `trustedKeyId` / `trustedKeyBase64`
     * multipart fields used by the inspect-archive trust-helper. Returns
     * `null` when both are absent (the common case). Throws
     * `\InvalidArgumentException` for half-supplied / malformed input
     * — the controller catches that and turns it into a 400 with a
     * precise message before any signature-verification work runs.
     *
     * @return array{keyId:string,publicKeyBase64:string}|null
     */
    private function extractTrustedKeyOverride(Request $request): ?array
    {
        $rawKeyId = $request->request->get('trustedKeyId');
        $rawKeyB64 = $request->request->get('trustedKeyBase64');

        $keyId = is_string($rawKeyId) ? trim($rawKeyId) : '';
        $keyB64 = is_string($rawKeyB64) ? trim($rawKeyB64) : '';

        if ($keyId === '' && $keyB64 === '') {
            return null;
        }
        if ($keyId === '' || $keyB64 === '') {
            throw new \InvalidArgumentException(
                'trustedKeyId and trustedKeyBase64 must be provided together (or both omitted).',
            );
        }

        $decoded = base64_decode($keyB64, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException(
                'trustedKeyBase64 is not valid base64. Paste the publisher\'s 32-byte Ed25519 public key as base64.',
            );
        }
        $expectedLen = defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
        if (strlen($decoded) !== $expectedLen) {
            throw new \InvalidArgumentException(sprintf(
                'trustedKeyBase64 decodes to %d bytes; an Ed25519 public key must be %d bytes.',
                strlen($decoded),
                $expectedLen,
            ));
        }

        return ['keyId' => $keyId, 'publicKeyBase64' => $keyB64];
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
            [$input, $archive] = $this->extractInstallInput($request, validate: true);
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
     * Extracts the install/update payload from either a JSON body
     * (registry|url|paste sources) or multipart/form-data (archive
     * source). When `$validate` is true, JSON bodies are validated
     * against `requests/admin/plugins/install.json` so a malformed
     * payload returns a structured 400 from `validateRequest()`.
     *
     * @return array{0: array<string,mixed>, 1: \Symfony\Component\HttpFoundation\File\UploadedFile|null}
     */
    private function extractInstallInput(Request $request, bool $validate = false): array
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
        if ($validate) {
            // The schema's `oneOf` over (registry|url|paste) variants
            // plus `additionalProperties: false` per variant rejects
            // mis-shaped bodies BEFORE the service layer fans the
            // payload out to the resolver. `expectedPluginId` (URL-
            // pinned by the update controller) is appended AFTER this
            // call so it does not need to be in the schema.
            $payload = $this->validateRequest($request, 'requests/admin/plugins/install', $this->jsonSchemaValidationService);
            return [$this->toAssocArray($payload), null];
        }
        $raw = (string) $request->getContent();
        $payload = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        return [$this->toAssocArray($payload), null];
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
     * @route /admin/plugins/{pluginId}/pin
     * @method POST
     */
    public function pin(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->pin($pluginId),
                'responses/admin/plugins/plugin_envelope'
            );
        } catch (\Throwable $e) {
            return $this->respondWithError($e);
        }
    }

    /**
     * @route /admin/plugins/{pluginId}/unpin
     * @method POST
     */
    public function unpin(string $pluginId): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->pluginAdminService->unpin($pluginId),
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
            $confirmed = $this->asStringField($this->toAssocArray($payload), 'confirmedPluginId');
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
