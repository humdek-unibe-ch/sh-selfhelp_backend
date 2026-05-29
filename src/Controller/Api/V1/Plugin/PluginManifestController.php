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
 * The endpoint is public read-only: it returns only the fields the
 * frontend `PluginRuntime` consumes from `IPluginManifestEntry`. It
 * deliberately does NOT include:
 *   - the raw `manifest` blob (would leak `security.signing.acceptedKeyIds`,
 *     `security.externalHosts`, `backend.composer.repository` URLs +
 *     credentials hints),
 *   - the lock-file snapshot (signatures + keyIds for every installed
 *     plugin),
 *   - operation history, secrets, or checksums.
 *
 * Anything that needs the full manifest or the lock-file (admin UI
 * detail pages, doctor command) goes through the authenticated
 * `/admin/plugins/...` endpoints instead.
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
                    // Plugin id is shipped under `pluginId` only — it
                    // matches the frontend `IPluginManifestEntry`
                    // contract (`PluginRuntime.ts`) and the admin
                    // types. Sync scripts (frontend + mobile) read
                    // `pluginId` directly.
                    'pluginId' => $plugin->getPluginId(),
                    'name' => $plugin->getName(),
                    'version' => $plugin->getVersion(),
                    'pluginApiVersion' => $plugin->getPluginApiVersion(),
                    'trustLevel' => $plugin->getTrustLevel(),
                    'enabled' => $plugin->isEnabled(),
                    'capabilities' => $capabilities,
                    'featureFlags' => $featureFlagDefaults,
                    'frontendRuntimeUrl' => $this->versionedAssetUrl($plugin->getFrontendRuntimeUrl(), $plugin),
                    'frontendRuntimeStylesheetUrl' => $this->versionedAssetUrl($plugin->getFrontendRuntimeStylesheetUrl(), $plugin),
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

    private function versionedAssetUrl(?string $url, \App\Entity\Plugin\Plugin $plugin): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $version = hash('sha256', implode('|', [
            $plugin->getPluginId(),
            $plugin->getVersion(),
            (string) $plugin->getId(),
            $plugin->getUpdatedAt()->format(DATE_ATOM),
            $plugin->getFrontendRuntimeIntegrity() ?? '',
        ]));

        return $url . $separator . '_shPluginAsset=' . substr($version, 0, 16);
    }
}
