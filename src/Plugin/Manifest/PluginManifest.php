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

    /**
     * Annotation-only string narrowing that preserves the previous inline
     * `(string) $value` casts: for the scalar values the manifest schema
     * guarantees the result is identical, and the cast itself is unchanged
     * at runtime. Non-scalars (impossible for these schema-validated
     * fields) collapse to '' instead of triggering a cast warning.
     *
     * @param mixed $value
     */
    private static function asString($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read a value from the raw manifest by key path, returning null when an
     * intermediate segment is absent or not an array. Mirrors the previous
     * `$this->data['a']['b'] ?? null` reads, which already tolerated missing
     * sub-trees through the null-coalescing operator.
     *
     * @return mixed
     */
    private function raw(string ...$keys)
    {
        $value = $this->data;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function getPluginId(): string
    {
        return self::asString($this->data['id'] ?? null);
    }

    public function getName(): string
    {
        return self::asString($this->data['name'] ?? null);
    }

    public function getDescription(): ?string
    {
        $description = $this->data['description'] ?? null;
        return $description !== null ? self::asString($description) : null;
    }

    public function getVersion(): string
    {
        return self::asString($this->data['version'] ?? null);
    }

    public function getPluginApiVersion(): string
    {
        return self::asString($this->data['pluginApiVersion'] ?? null);
    }

    public function getCmsCompatibilityRange(): string
    {
        return self::asString($this->raw('compatibility', 'selfhelp') ?? '*');
    }

    public function getTrustLevel(): string
    {
        return self::asString($this->raw('security', 'trustLevel') ?? 'untrusted');
    }

    /** @return array<int,string> */
    public function getCapabilities(): array
    {
        $caps = $this->raw('security', 'capabilities') ?? [];
        if (!is_array($caps)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => self::asString($value), $caps));
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /** @return array<int, array<string,mixed>> */
    public function getDependencies(): array
    {
        $deps = $this->data['dependencies'] ?? [];
        if (!is_array($deps)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($deps);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getConflicts(): array
    {
        $conflicts = $this->data['conflicts'] ?? [];
        if (!is_array($conflicts)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($conflicts);
        return $list;
    }

    /**
     * Composer package name from `backend.composer.package`.
     */
    public function getBackendPackage(): ?string
    {
        $pkg = $this->raw('backend', 'composer', 'package');
        return is_string($pkg) && $pkg !== '' ? $pkg : null;
    }

    /**
     * Composer version constraint from `backend.composer.version`.
     */
    public function getBackendComposerVersion(): ?string
    {
        $v = $this->raw('backend', 'composer', 'version');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @return array{type:string,url:string,reference?:string}|null
     */
    public function getBackendComposerRepository(): ?array
    {
        $repo = $this->raw('backend', 'composer', 'repository');
        if (!is_array($repo)) {
            return null;
        }
        $type = $repo['type'] ?? null;
        $url = $repo['url'] ?? null;
        if (!is_string($type) || $type === '' || !is_string($url) || $url === '') {
            return null;
        }
        $out = ['type' => $type, 'url' => $url];
        if (isset($repo['reference']) && is_string($repo['reference']) && $repo['reference'] !== '') {
            $out['reference'] = $repo['reference'];
        }
        return $out;
    }

    public function getBackendBundleClass(): ?string
    {
        $bundleClass = $this->raw('backend', 'bundleClass');
        return $bundleClass !== null ? self::asString($bundleClass) : null;
    }

    /**
     * PHP namespace under which the plugin ships its Doctrine migrations
     * (`<plugin>/src/Migrations/`). Used by `PluginMigrationsRunner` to
     * register the plugin's migration directory with a per-operation
     * `DependencyFactory` so finalize() can apply pending migrations
     * against the shared `doctrine_migration_versions` metadata table.
     * Returns `null` when the manifest declares no backend bundle.
     */
    public function getBackendMigrationsNamespace(): ?string
    {
        $ns = $this->raw('backend', 'migrationsNamespace');
        if (is_string($ns) && $ns !== '') {
            return $ns;
        }
        $bundleClass = $this->getBackendBundleClass();
        if ($bundleClass === null || $bundleClass === '') {
            return null;
        }
        $lastSlash = strrpos($bundleClass, '\\');
        if ($lastSlash === false) {
            return null;
        }
        return substr($bundleClass, 0, $lastSlash) . '\\Migrations';
    }

    /**
     * Runtime ESM entrypoint path / URL. After the host has promoted a
     * `.shplugin` to `public/plugin-artifacts/<id>-<ver>/`, this returns
     * the rewritten `/plugin-artifacts/...` URL. For registry installs
     * this returns the canonical https URL.
     */
    public function getFrontendRuntimeEntrypoint(): ?string
    {
        $v = $this->raw('frontend', 'runtime', 'entrypoint');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getFrontendRuntimeStylesheet(): ?string
    {
        $v = $this->raw('frontend', 'runtime', 'stylesheet');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getFrontendRuntimeFormat(): string
    {
        $v = $this->raw('frontend', 'runtime', 'format');
        return is_string($v) && $v !== '' ? $v : 'esm';
    }

    public function getFrontendRuntimeIntegrity(): ?string
    {
        $v = $this->raw('frontend', 'runtime', 'integrity');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getFrontendRuntimeStylesheetIntegrity(): ?string
    {
        $v = $this->raw('frontend', 'runtime', 'stylesheetIntegrity');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getFrontendDevEntrypointUrl(): ?string
    {
        $v = $this->raw('frontend', 'runtime', 'devEntrypointUrl');
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getMobilePackage(): ?string
    {
        $package = $this->raw('mobile', 'package');
        return $package !== null ? self::asString($package) : null;
    }

    public function getMobilePackageVersion(): ?string
    {
        $version = $this->raw('mobile', 'version');
        return $version !== null ? self::asString($version) : null;
    }

    /**
     * Whether the manifest declares signing as REQUIRED. The host
     * always enforces `SELFHELP_PLUGIN_REQUIRE_SIGNATURE` first; this
     * additional plugin-side opt-in lets a publisher demand stricter
     * verification.
     */
    public function getSigningRequired(): bool
    {
        return (bool) ($this->raw('security', 'signing', 'required') ?? false);
    }

    /**
     * @return list<string>
     */
    public function getSigningAcceptedKeyIds(): array
    {
        $ids = $this->raw('security', 'signing', 'acceptedKeyIds') ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => self::asString($value), $ids));
    }

    /** @return array<int, array<string,mixed>> */
    public function getPermissions(): array
    {
        $perms = $this->data['permissions'] ?? [];
        if (!is_array($perms)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($perms);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getStyles(): array
    {
        $styles = $this->data['styles'] ?? [];
        if (!is_array($styles)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($styles);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getApiRoutes(): array
    {
        $routes = $this->data['apiRoutes'] ?? [];
        if (!is_array($routes)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($routes);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getAdminPages(): array
    {
        $pages = $this->data['adminPages'] ?? [];
        if (!is_array($pages)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($pages);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getRealtimeTopics(): array
    {
        $topics = $this->data['realtimeTopics'] ?? [];
        if (!is_array($topics)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($topics);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getFeatureFlags(): array
    {
        $flags = $this->data['featureFlags'] ?? [];
        if (!is_array($flags)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($flags);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getLookupExtensions(): array
    {
        $lookups = $this->raw('lookups', 'extends') ?? [];
        if (!is_array($lookups)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($lookups);
        return $list;
    }

    /** @return array<int, array<string,mixed>> */
    public function getScheduledJobs(): array
    {
        $jobs = $this->data['scheduledJobs'] ?? [];
        if (!is_array($jobs)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($jobs);
        return $list;
    }

    /** @return array<string, array<string>> */
    public function getCspRules(): array
    {
        $csp = $this->raw('security', 'cspRules') ?? [];
        if (!is_array($csp)) {
            return [];
        }
        /** @var array<string, array<string>> $csp */
        return $csp;
    }

    /** @return array<int, array<string,mixed>> */
    public function getExternalHosts(): array
    {
        $hosts = $this->raw('security', 'externalHosts') ?? [];
        if (!is_array($hosts)) {
            return [];
        }
        /** @var array<int, array<string,mixed>> $list */
        $list = array_values($hosts);
        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDataAccess(): array
    {
        $access = $this->data['dataAccess'] ?? [];
        if (!is_array($access)) {
            return [];
        }
        /** @var array<string, mixed> $access */
        return $access;
    }

    /**
     * @return list<string>
     */
    public function getDataAccessRead(): array
    {
        $access = $this->getDataAccess();
        $list = $access['read'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => self::asString($value), $list));
    }

    /**
     * @return list<string>
     */
    public function getDataAccessWrite(): array
    {
        $access = $this->getDataAccess();
        $list = $access['write'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => self::asString($value), $list));
    }

    /**
     * @return list<string>
     */
    public function getOwnedTables(): array
    {
        $access = $this->getDataAccess();
        $list = $access['ownedTables'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => self::asString($value), $list));
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
        $healthEndpoint = $this->data['healthEndpoint'] ?? null;
        return $healthEndpoint !== null ? self::asString($healthEndpoint) : null;
    }

    /** @return RawManifest */
    public function toArray(): array
    {
        return $this->data;
    }
}
