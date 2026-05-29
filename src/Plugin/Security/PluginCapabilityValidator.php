<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use App\Entity\Plugin\Plugin;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;

/**
 * Validates the capability set requested by a plugin manifest against
 * the trust level it declares and the deny-by-default capability
 * matrix in `CapabilityCatalog`.
 *
 * The validator is intentionally pure: it never touches the database,
 * the file system, or the cache. The installer/updater run it before
 * persisting anything so the operation can be rejected with no side
 * effects.
 *
 * Cross-checks performed:
 *
 *   1. Trust level is one of `official`, `reviewed`, `untrusted`.
 *   2. Every capability in the manifest is a known catalog key.
 *   3. The trust level is allowed to grant every requested capability.
 *   4. Manifest features that imply a capability also declare it:
 *      - backend bundle → `backendBundle`
 *      - admin pages → `adminPages`
 *      - styles → `frontendStyles`
 *      - mobile styles → `mobileStyles`
 *      - api routes → `backendBundle` (the host persists each
 *        `plugin.json#apiRoutes` entry into `api_routes` via
 *        `PluginApiRouteSynchronizer`; the route's controller
 *        therefore lives inside the plugin's backend bundle)
 *      - realtime topics → `realtimePublish`
 *      - scheduled jobs → `scheduledJobs` and `backendBundle`
 *      - lookups.extends → `lookupExtend` and/or `lookupOwnGroup`
 *   5. Frontend runtime URL is HTTPS-only unless the source is a
 *      `.shplugin` archive (host-promoted to `/plugin-artifacts/...`),
 *      a humdek-public registry entry, an `untrusted` plugin, or the
 *      host is running in `dev` env (`devEntrypointUrl` allowed).
 */
final class PluginCapabilityValidator
{
    /**
     * Source kinds that don't need HTTPS for `frontend.runtime.entrypoint`.
     * Archive sources expose `/plugin-artifacts/...` after promotion;
     * untrusted plugins typically run locally during development.
     *
     * @var list<string>
     */
    private const TRUSTED_RUNTIME_SOURCE_KINDS = [
        ResolvedSource::KIND_ARCHIVE,
        ResolvedSource::KIND_PASTE,
    ];

    public function __construct(
        private readonly string $appEnv = 'prod',
    ) {
    }

    /**
     * @return list<string> Validated capabilities (deduplicated, sorted).
     * @throws PluginCapabilityViolationException
     */
    public function validate(PluginManifest $manifest, ?ResolvedSource $source = null): array
    {
        $trustLevel = $manifest->getTrustLevel();
        if (!in_array($trustLevel, [Plugin::TRUST_OFFICIAL, Plugin::TRUST_REVIEWED, Plugin::TRUST_UNTRUSTED], true)) {
            throw new PluginCapabilityViolationException(sprintf(
                'Unknown trust level "%s" in plugin manifest. Allowed: official, reviewed, untrusted.',
                $trustLevel
            ));
        }

        $declared = array_values($manifest->getCapabilities());
        $allowed = CapabilityCatalog::all();
        foreach ($declared as $capability) {
            if (!in_array($capability, $allowed, true)) {
                throw new PluginCapabilityViolationException(sprintf(
                    'Unknown capability "%s" declared by plugin "%s". See CapabilityCatalog::all().',
                    $capability,
                    $manifest->getPluginId()
                ));
            }
            if (!CapabilityCatalog::allows($trustLevel, $capability)) {
                throw new PluginCapabilityViolationException(sprintf(
                    'Capability "%s" cannot be granted at trust level "%s" (plugin "%s"). Raise the trust level or remove the capability.',
                    $capability,
                    $trustLevel,
                    $manifest->getPluginId()
                ));
            }
        }

        $this->assertImpliedCapabilities($manifest, $declared);
        $this->assertFrontendRuntimeUrlScheme($manifest, $source);

        $result = array_values(array_unique($declared));
        sort($result);
        return $result;
    }

    /**
     * HTTPS-only for `frontend.runtime.entrypoint` unless the source
     * is a `.shplugin` archive (promoted to a host-served path), a
     * humdek-public registry entry, the plugin is `untrusted`, or the
     * host is running in dev.
     */
    private function assertFrontendRuntimeUrlScheme(PluginManifest $manifest, ?ResolvedSource $source): void
    {
        $entrypoint = $manifest->getFrontendRuntimeEntrypoint();
        if ($entrypoint === null || $entrypoint === '') {
            return;
        }

        if (str_starts_with($entrypoint, '/')) {
            // Host-relative path (e.g. /plugin-artifacts/...).
            return;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $entrypoint, $matches) !== 1) {
            // Bare relative path inside the archive (e.g. dist/plugin.esm.js).
            // The archive promoter rewrites these to /plugin-artifacts/... before finalize().
            return;
        }

        $scheme = strtolower($matches[1]);
        if ($scheme === 'https') {
            return;
        }

        // Allow http only on a narrow whitelist.
        if ($this->appEnv === 'dev') {
            return;
        }
        if ($manifest->getTrustLevel() === Plugin::TRUST_UNTRUSTED) {
            return;
        }
        if ($source !== null) {
            if (in_array($source->kind, self::TRUSTED_RUNTIME_SOURCE_KINDS, true)) {
                return;
            }
            if ($source->kind === ResolvedSource::KIND_REGISTRY && $source->sourceName === 'humdek-public') {
                return;
            }
        }

        throw new PluginCapabilityViolationException(sprintf(
            'Plugin "%s" declares frontend.runtime.entrypoint with scheme "%s://" — only HTTPS is allowed for production plugins from registry/url sources. Either ship a `.shplugin` archive (host serves /plugin-artifacts/... directly) or publish over HTTPS.',
            $manifest->getPluginId(),
            $scheme,
        ));
    }

    /**
     * @param list<string> $declared
     */
    private function assertImpliedCapabilities(PluginManifest $manifest, array $declared): void
    {
        $pluginId = $manifest->getPluginId();
        $must = static function (string $capability, string $reason) use ($declared, $pluginId): void {
            if (!in_array($capability, $declared, true)) {
                throw new PluginCapabilityViolationException(sprintf(
                    'Plugin "%s" must declare capability "%s" (%s).',
                    $pluginId,
                    $capability,
                    $reason
                ));
            }
        };

        if ($manifest->getBackendPackage() !== null || $manifest->getBackendBundleClass() !== null) {
            $must(CapabilityCatalog::CAP_BACKEND_BUNDLE, 'manifest declares a backend bundle');
        }

        if (!empty($manifest->getAdminPages())) {
            $must(CapabilityCatalog::CAP_ADMIN_PAGES, 'manifest declares admin pages');
        }

        if (!empty($manifest->getStyles())) {
            $must(CapabilityCatalog::CAP_FRONTEND_STYLES, 'manifest declares frontend styles');
        }

        if (!empty($manifest->getApiRoutes())) {
            $must(CapabilityCatalog::CAP_BACKEND_BUNDLE, 'manifest declares API routes (backend required)');
        }

        if (!empty($manifest->getRealtimeTopics())) {
            $must(CapabilityCatalog::CAP_REALTIME_PUBLISH, 'manifest declares realtime topics');
        }

        if (!empty($manifest->getScheduledJobs())) {
            $must(CapabilityCatalog::CAP_SCHEDULED_JOBS, 'manifest declares scheduled jobs');
            $must(CapabilityCatalog::CAP_BACKEND_BUNDLE, 'scheduled jobs require backend code');
        }

        $lookupExtensions = $manifest->getLookupExtensions();
        foreach ($lookupExtensions as $extension) {
            $ownership = isset($extension['ownership']) ? (string) $extension['ownership'] : (isset($extension['policy']) ? (string) $extension['policy'] : 'plugin_extendable');
            if ($ownership === 'plugin_owned') {
                $must(CapabilityCatalog::CAP_LOOKUP_OWN_GROUP, 'manifest declares a plugin-owned lookup group');
            } else {
                $must(CapabilityCatalog::CAP_LOOKUP_EXTEND, 'manifest extends a core lookup group');
            }
        }
    }
}
