<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Versioning;

/**
 * The single source of truth for plugin <-> host compatibility semantics on the
 * backend. Every backend caller that asks "can this plugin run on this host?"
 * goes through here so the rules can never drift between the version summary,
 * the core-update preflight, the registry resolver, and the manifest validator.
 *
 * Two independent axes, each a {@see SemverHelper} range check:
 *
 *  1. CORE axis — the SelfHelp core (backend) version the plugin supports.
 *     - author manifest field: `compatibility.selfhelp`
 *     - registry release field: `compatibility.core`
 *     Both mean the same thing; the publisher maps `selfhelp` -> `core` at build
 *     time. Checked with {@see coreSatisfied()} / {@see manifestCoreRange()}.
 *
 *  2. PLUGIN-API axis — the plugin-API (SDK) contract the plugin needs.
 *     - author manifest field: top-level `pluginApiVersion`
 *     - registry release field: `compatibility.pluginApi`
 *     Checked with {@see pluginApiSatisfied()} / {@see manifestPluginApiRange()}.
 *
 * An empty/absent range means "no constraint" => compatible (a plugin that omits
 * a range opts out of that gate). The `blocked` flag and security advisories are
 * NOT range checks: they are evaluated separately — `blocked` by the registry
 * resolver, advisories by {@see \App\Service\System\SystemAdvisoryService} — and
 * documented in `docs/developer/26-plugin-compatibility-rules.md`.
 */
final class PluginCompatibility
{
    /** True when $coreVersion satisfies $coreRange (empty/null range = unconstrained). */
    public static function coreSatisfied(string $coreVersion, ?string $coreRange): bool
    {
        return $coreRange === null || trim($coreRange) === '' || SemverHelper::satisfies($coreVersion, $coreRange);
    }

    /** True when $pluginApiVersion satisfies $apiRange (empty/null range = unconstrained). */
    public static function pluginApiSatisfied(string $pluginApiVersion, ?string $apiRange): bool
    {
        return $apiRange === null || trim($apiRange) === '' || SemverHelper::satisfies($pluginApiVersion, $apiRange);
    }

    /**
     * The core (SelfHelp backend) range an installed plugin manifest declares,
     * or null when it declares none. Prefers `compatibility.selfhelp` (author
     * field) and falls back to `compatibility.core` (registry field) so a row
     * persisted from either shape resolves consistently.
     *
     * @param array<string,mixed> $manifest
     */
    public static function manifestCoreRange(array $manifest): ?string
    {
        $compatibility = $manifest['compatibility'] ?? null;
        if (!is_array($compatibility)) {
            return null;
        }
        $range = $compatibility['selfhelp'] ?? ($compatibility['core'] ?? null);

        return is_string($range) && $range !== '' ? $range : null;
    }

    /**
     * The plugin-API range an installed plugin manifest declares, or null when
     * it declares none. Prefers the top-level `pluginApiVersion` (author field)
     * and falls back to `compatibility.pluginApi` (registry field).
     *
     * @param array<string,mixed> $manifest
     */
    public static function manifestPluginApiRange(array $manifest): ?string
    {
        $top = $manifest['pluginApiVersion'] ?? null;
        if (is_string($top) && $top !== '') {
            return $top;
        }
        $compatibility = $manifest['compatibility'] ?? null;
        if (is_array($compatibility)) {
            $api = $compatibility['pluginApi'] ?? null;
            if (is_string($api) && $api !== '') {
                return $api;
            }
        }

        return null;
    }

    /**
     * Whether an installed plugin manifest is compatible with a given core
     * version on the CORE axis (the gate the version summary + core-update
     * preflight use). The plugin-API axis is enforced separately at
     * install/update time (registry resolver + manifest validator).
     *
     * @param array<string,mixed> $manifest
     */
    public static function isManifestCoreCompatible(array $manifest, string $coreVersion): bool
    {
        return self::coreSatisfied($coreVersion, self::manifestCoreRange($manifest));
    }
}
