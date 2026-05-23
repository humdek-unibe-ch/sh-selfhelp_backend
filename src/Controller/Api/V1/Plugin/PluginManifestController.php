<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Plugin;

use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Service\PluginAdminService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-side manifest endpoint used by:
 *   - Frontend `plugins:sync` script (build-time fetch of installed
 *     plugin manifests, mirrored into the frontend registry).
 *   - Mobile `plugins:sync` script (same, per EAS profile).
 *   - Admin UI for live manifest viewing.
 *
 * The endpoint is public read-only: it returns only enabled plugins
 * and only the manifest data (no secrets, no checksums, no operation
 * history). Authenticated callers see the same payload as anonymous
 * — sensitive data lives under `/admin/plugins/...`.
 *
 *   GET /cms-api/v1/plugins/manifest
 */
final class PluginManifestController extends AbstractController
{
    public function __construct(
        private readonly PluginRegistryService $pluginRegistry,
        private readonly PluginAdminService $pluginAdminService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * @route /plugins/manifest
     * @method GET
     */
    public function manifest(): JsonResponse
    {
        try {
            $plugins = [];
            foreach ($this->pluginRegistry->getEnabled() as $plugin) {
                $manifest = $plugin->getManifestJson();
                $manifestArray = is_array($manifest) ? $manifest : [];
                $featureFlagDefaults = [];
                foreach ($manifestArray['featureFlags'] ?? [] as $flag) {
                    if (!is_array($flag) || !isset($flag['key'])) continue;
                    $featureFlagDefaults[(string) $flag['key']] = (bool) ($flag['defaultEnabled'] ?? false);
                }
                $capabilities = [];
                $security = $manifestArray['security'] ?? null;
                if (is_array($security) && isset($security['capabilities']) && is_array($security['capabilities'])) {
                    foreach ($security['capabilities'] as $cap) {
                        if (is_string($cap)) {
                            $capabilities[] = $cap;
                        }
                    }
                }
                $plugins[] = [
                    // Plugin id is shipped under BOTH `id` and `pluginId`:
                    // `id` keeps the legacy script consumers happy,
                    // `pluginId` matches the frontend `IPluginManifestEntry`
                    // contract (`PluginRuntime.ts`) and the admin types.
                    'id' => $plugin->getPluginId(),
                    'pluginId' => $plugin->getPluginId(),
                    'name' => $plugin->getName(),
                    'version' => $plugin->getVersion(),
                    'pluginApiVersion' => $plugin->getPluginApiVersion(),
                    'trustLevel' => $plugin->getTrustLevel(),
                    'enabled' => $plugin->isEnabled(),
                    'capabilities' => $capabilities,
                    'featureFlags' => $featureFlagDefaults,
                    'manifest' => $manifest,
                    'frontendRuntimeUrl' => $plugin->getFrontendRuntimeUrl(),
                    'frontendRuntimeStylesheetUrl' => $plugin->getFrontendRuntimeStylesheetUrl(),
                    'frontendRuntimeIntegrity' => $plugin->getFrontendRuntimeIntegrity(),
                    'frontendRuntimeFormat' => $plugin->getFrontendRuntimeFormat(),
                    'mobilePackage' => $plugin->getMobilePackage(),
                    'mobilePackageVersion' => $plugin->getMobilePackageVersion(),
                ];
            }

            return $this->responseFormatter->formatSuccess(
                [
                    'cmsVersion' => $this->pluginAdminService->getCmsVersion(),
                    'sdkApiVersion' => $this->pluginAdminService->getSdkApiVersion(),
                    'plugins' => $plugins,
                    'lockfileSnapshot' => $this->pluginAdminService->getLockFileSnapshot(),
                    'safeMode' => $this->pluginAdminService->isSafeModeOn(),
                ],
                'responses/frontend/plugin_manifest'
            );
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
            return $this->responseFormatter->formatError($e->getMessage(), $status);
        }
    }
}
