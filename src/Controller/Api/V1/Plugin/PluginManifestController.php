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
 * frontend `PluginRuntime` consumes from `IPluginManifestEntry`, plus the
 * declared `jsonLogicOperations` (name, description) that the admin
 * condition builder lists as functions. It deliberately does NOT include:
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
                $manifestArray = $plugin->getManifestJson();
                $featureFlagDefaults = [];
                $featureFlags = $manifestArray['featureFlags'] ?? null;
                if (is_array($featureFlags)) {
                    foreach ($featureFlags as $flag) {
                        if (!is_array($flag) || !isset($flag['key']) || !is_scalar($flag['key'])) {
                            continue;
                        }
                        $featureFlagDefaults[(string) $flag['key']] = (bool) ($flag['defaultEnabled'] ?? false);
                    }
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
                // Echo the compatibility ranges so the SelfHelp Manager preflight
                // can gate the plugin's mobile package against the selected
                // mobile-preview image's `mobileRendererVersion` (compatibility.mobile),
                // alongside the existing reactNative/expoSdk axes.
                $compatibility = [];
                $rawCompatibility = $manifestArray['compatibility'] ?? null;
                if (is_array($rawCompatibility)) {
                    foreach (['selfhelp', 'php', 'node', 'react', 'reactNative', 'expoSdk', 'mobile'] as $key) {
                        $value = $rawCompatibility[$key] ?? null;
                        if (is_string($value) && $value !== '') {
                            $compatibility[$key] = $value;
                        }
                    }
                }
                $entry = [
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
                    // An empty map must stay a JSON object (`{}`, schema `type: object`), never `[]`.
                    'featureFlags' => $featureFlagDefaults === [] ? new \stdClass() : $featureFlagDefaults,
                    'frontendRuntimeUrl' => $this->versionedAssetUrl($plugin->getFrontendRuntimeUrl(), $plugin),
                    'frontendRuntimeStylesheetUrl' => $this->versionedAssetUrl($plugin->getFrontendRuntimeStylesheetUrl(), $plugin),
                    'frontendRuntimeIntegrity' => $plugin->getFrontendRuntimeIntegrity(),
                    'frontendRuntimeFormat' => $plugin->getFrontendRuntimeFormat(),
                    'mobilePackage' => $plugin->getMobilePackage(),
                    'mobilePackageVersion' => $plugin->getMobilePackageVersion(),
                    // Rule 4.13: each admin page carries the one permission
                    // that opens it, so the frontend server guard can check
                    // it when the page is requested.
                    'adminPages' => $this->adminPages($manifestArray),
                    // C16: the web condition builder lists these as functions.
                    'jsonLogicOperations' => $this->jsonLogicOperations($manifestArray),
                ];
                // Only emit `compatibility` when non-empty: an empty PHP array
                // would JSON-encode as `[]` and fail the schema's `type: object`.
                if ($compatibility !== []) {
                    $entry['compatibility'] = $compatibility;
                }
                $plugins[] = $entry;
            }

            return $this->responseFormatter->formatSuccess(
                [
                    'cmsVersion' => $this->pluginAdminService->getCmsVersion(),
                    'sdkApiVersion' => $this->pluginAdminService->getSdkApiVersion(),
                    'plugins' => $plugins,
                    'safeMode' => $this->pluginAdminService->isSafeModeOn(),
                ],
                'responses/frontend/plugin_manifest',
                Response::HTTP_OK,
                false,
                // Skip the serializer round trip: it would decode the empty `featureFlags` object back to `[]`.
                true,
            );
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
            return $this->responseFormatter->formatError($e->getMessage(), $status);
        }
    }

    /**
     * Public projection of `adminPages`: slug, label, permission and the
     * optional icon. Entries without a string slug or permission are
     * dropped (the manifest validator refuses them at install).
     *
     * @param array<string,mixed> $manifestArray
     * @return list<array{slug: string, label: string, permission: string, icon?: string}>
     */
    private function adminPages(array $manifestArray): array
    {
        $raw = $manifestArray['adminPages'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $pages = [];
        foreach ($raw as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug = $page['slug'] ?? null;
            $permission = $page['permission'] ?? null;
            if (!is_string($slug) || $slug === '' || !is_string($permission) || $permission === '') {
                continue;
            }
            $label = $page['label'] ?? null;
            $item = [
                'slug' => $slug,
                'label' => is_string($label) ? $label : $slug,
                'permission' => $permission,
            ];
            $icon = $page['icon'] ?? null;
            if (is_string($icon) && $icon !== '') {
                $item['icon'] = $icon;
            }
            $pages[] = $item;
        }

        return $pages;
    }

    /**
     * Public projection of `jsonLogicOperations` (C16): the name and the
     * optional description of every operation the plugin declares, so the web
     * condition builder can list them as functions. Only enabled plugins reach
     * this method. A declared name is listed whether or not the plugin ships
     * the implementation: the host registers the ones that have one.
     *
     * @param array<string,mixed> $manifestArray
     * @return list<array{name: string, description?: string}>
     */
    private function jsonLogicOperations(array $manifestArray): array
    {
        $raw = $manifestArray['jsonLogicOperations'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $operations = [];
        foreach ($raw as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $name = $operation['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $item = ['name' => $name];
            $description = $operation['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $item['description'] = $description;
            }
            $operations[] = $item;
        }

        return $operations;
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
