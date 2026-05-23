<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Manifest;

/**
 * Immutable PHP DTO mirroring `plugin.json`.
 *
 * Built by `PluginManifestLoader::load()` after schema validation, then
 * consumed by every plugin-management code path (installer, registry,
 * permission validator, doctor command, admin UI).
 *
 * The DTO is intentionally simple: arrays of associative arrays for
 * sub-objects (manifest sub-trees are kept structural so we don't need
 * a full DTO hierarchy). Strongly-typed accessors guard the must-have
 * fields; everything else is exposed through `toArray()`.
 *
 * @phpstan-type RawManifest array<string,mixed>
 */
final class PluginManifest
{
    /**
     * @param RawManifest $data
     */
    public function __construct(private array $data)
    {
    }

    public function getPluginId(): string
    {
        return (string) $this->data['id'];
    }

    public function getName(): string
    {
        return (string) $this->data['name'];
    }

    public function getDescription(): ?string
    {
        return isset($this->data['description']) ? (string) $this->data['description'] : null;
    }

    public function getVersion(): string
    {
        return (string) $this->data['version'];
    }

    public function getPluginApiVersion(): string
    {
        return (string) $this->data['pluginApiVersion'];
    }

    public function getCmsCompatibilityRange(): string
    {
        return (string) ($this->data['compatibility']['selfhelp'] ?? '*');
    }

    public function getTrustLevel(): string
    {
        return (string) ($this->data['security']['trustLevel'] ?? 'untrusted');
    }

    /** @return array<int,string> */
    public function getCapabilities(): array
    {
        $caps = $this->data['security']['capabilities'] ?? [];
        return is_array($caps) ? array_values(array_map('strval', $caps)) : [];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /** @return array<int, array<string,mixed>> */
    public function getDependencies(): array
    {
        $deps = $this->data['dependencies'] ?? [];
        return is_array($deps) ? array_values($deps) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getConflicts(): array
    {
        $conflicts = $this->data['conflicts'] ?? [];
        return is_array($conflicts) ? array_values($conflicts) : [];
    }

    public function getBackendPackage(): ?string
    {
        return isset($this->data['backend']['package']) ? (string) $this->data['backend']['package'] : null;
    }

    public function getBackendBundleClass(): ?string
    {
        return isset($this->data['backend']['bundleClass']) ? (string) $this->data['backend']['bundleClass'] : null;
    }

    public function getFrontendPackage(): ?string
    {
        return isset($this->data['frontend']['package']) ? (string) $this->data['frontend']['package'] : null;
    }

    public function getFrontendPackageVersion(): ?string
    {
        return isset($this->data['frontend']['version']) ? (string) $this->data['frontend']['version'] : null;
    }

    public function getMobilePackage(): ?string
    {
        return isset($this->data['mobile']['package']) ? (string) $this->data['mobile']['package'] : null;
    }

    public function getMobilePackageVersion(): ?string
    {
        return isset($this->data['mobile']['version']) ? (string) $this->data['mobile']['version'] : null;
    }

    /** @return array<int, array<string,mixed>> */
    public function getPermissions(): array
    {
        $perms = $this->data['permissions'] ?? [];
        return is_array($perms) ? array_values($perms) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getStyles(): array
    {
        $styles = $this->data['styles'] ?? [];
        return is_array($styles) ? array_values($styles) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getApiRoutes(): array
    {
        $routes = $this->data['apiRoutes'] ?? [];
        return is_array($routes) ? array_values($routes) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getAdminPages(): array
    {
        $pages = $this->data['adminPages'] ?? [];
        return is_array($pages) ? array_values($pages) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getRealtimeTopics(): array
    {
        $topics = $this->data['realtimeTopics'] ?? [];
        return is_array($topics) ? array_values($topics) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getFeatureFlags(): array
    {
        $flags = $this->data['featureFlags'] ?? [];
        return is_array($flags) ? array_values($flags) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getLookupExtensions(): array
    {
        $lookups = $this->data['lookups']['extends'] ?? [];
        return is_array($lookups) ? array_values($lookups) : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getScheduledJobs(): array
    {
        $jobs = $this->data['scheduledJobs'] ?? [];
        return is_array($jobs) ? array_values($jobs) : [];
    }

    /** @return array<string, array<string>> */
    public function getCspRules(): array
    {
        $csp = $this->data['security']['cspRules'] ?? [];
        return is_array($csp) ? $csp : [];
    }

    /** @return array<int, array<string,mixed>> */
    public function getExternalHosts(): array
    {
        $hosts = $this->data['security']['externalHosts'] ?? [];
        return is_array($hosts) ? array_values($hosts) : [];
    }

    /**
     * @return array{read?: array<int,string>, write?: array<int,string>, delete?: array<int,string>}
     */
    public function getDataAccess(): array
    {
        $access = $this->data['dataAccess'] ?? [];
        return is_array($access) ? $access : [];
    }

    /**
     * @return list<string>
     */
    public function getDataAccessRead(): array
    {
        $access = $this->getDataAccess();
        $list = $access['read'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * @return list<string>
     */
    public function getDataAccessWrite(): array
    {
        $access = $this->getDataAccess();
        $list = $access['write'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * @return list<string>
     */
    public function getOwnedTables(): array
    {
        $access = $this->getDataAccess();
        $list = $access['ownedTables'] ?? [];
        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * Optional prefix for plugin-owned rows in `data_tables`
     * (e.g. `sh2_surveyjs_`). The host's data-access guard treats
     * tables matching this prefix as plugin-owned for write purposes.
     */
    public function getOwnedDataTablePrefix(): ?string
    {
        $access = $this->getDataAccess();
        $prefix = $access['ownedDataTablePrefix'] ?? null;
        return is_string($prefix) && $prefix !== '' ? $prefix : null;
    }

    /**
     * Alias for `getBackendBundleClass()` kept for symmetry with
     * `PluginDataAccessGuard` and other plugin-layer code that thinks
     * of "the plugin's bundle class" rather than "the backend bundle
     * class" (the manifest only declares backend bundles today).
     */
    public function getBundleClass(): ?string
    {
        return $this->getBackendBundleClass();
    }

    /**
     * Raw manifest JSON. The host treats the manifest as opaque after
     * load; the registry uses this for round-tripping and for the
     * `Plugin.manifestJson` column.
     *
     * @return RawManifest
     */
    public function getManifestJson(): array
    {
        return $this->data;
    }

    /**
     * Optional health endpoint URL — when present, the doctor command
     * HTTP-GETs this URL and surfaces its `status`/`message` response.
     * Keep absolute (https://...) so the host doesn't have to guess
     * the base URL.
     */
    public function getHealthEndpoint(): ?string
    {
        return isset($this->data['healthEndpoint']) ? (string) $this->data['healthEndpoint'] : null;
    }

    /** @return RawManifest */
    public function toArray(): array
    {
        return $this->data;
    }
}
