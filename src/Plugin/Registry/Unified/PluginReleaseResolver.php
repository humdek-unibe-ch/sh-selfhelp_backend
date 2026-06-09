<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

use App\Plugin\Versioning\SemverHelper;

/**
 * Multi-version plugin resolver for the unified registry.
 *
 * Deliberately mirrors the Manager's `@shm/resolver` `plugins.ts`
 * (`isPluginCompatible` / `resolveLatestCompatiblePlugin`) so the backend and
 * the Manager agree on what "compatible" and "newest compatible" mean for the
 * SAME registry document:
 *
 *   - a plugin release is compatible with a host when the host's core version
 *     satisfies `compatibility.core` AND the host's plugin-API version
 *     satisfies `compatibility.pluginApi`, and the release is not `blocked`;
 *   - the default selection is the NEWEST compatible version;
 *   - a requested target version is checked explicitly and blocked with a
 *     standardized {@see CompatibilityError} when incompatible;
 *   - when no version is compatible, the resolution carries a clear error;
 *   - older compatible versions remain valid (e.g. a pinned install).
 */
final class PluginReleaseResolver
{
    public function isCompatible(PluginRelease $release, string $coreVersion, string $pluginApiVersion): bool
    {
        if ($release->blocked) {
            return false;
        }
        return SemverHelper::satisfies($coreVersion, $release->compatibilityCore)
            && SemverHelper::satisfies($pluginApiVersion, $release->compatibilityPluginApi);
    }

    /**
     * Standardized compatibility error for a single release vs a target host,
     * or null when the release is compatible. Core axis is reported first.
     */
    public function compatibilityErrorFor(
        PluginRelease $release,
        string $coreVersion,
        string $pluginApiVersion,
        ?string $installedVersion = null,
    ): ?CompatibilityError {
        if (!SemverHelper::satisfies($coreVersion, $release->compatibilityCore)) {
            return CompatibilityError::pluginIncompatibleWithCore(
                pluginId: $release->id,
                currentVersion: $installedVersion,
                targetVersion: $release->version,
                requiredCoreRange: $release->compatibilityCore,
                coreVersion: $coreVersion,
            );
        }
        if (!SemverHelper::satisfies($pluginApiVersion, $release->compatibilityPluginApi)) {
            return CompatibilityError::pluginIncompatibleWithApi(
                pluginId: $release->id,
                currentVersion: $installedVersion,
                targetVersion: $release->version,
                requiredApiRange: $release->compatibilityPluginApi,
                pluginApiVersion: $pluginApiVersion,
            );
        }
        if ($release->blocked) {
            return new CompatibilityError(
                component: CompatibilityError::COMPONENT_PLUGIN,
                componentId: $release->id,
                currentVersion: $installedVersion,
                targetVersion: $release->version,
                requiredRange: $release->compatibilityCore,
                blocking: true,
                message: sprintf('Plugin %s@%s is blocked and cannot be installed.', $release->id, $release->version),
            );
        }
        return null;
    }

    /**
     * Resolve the newest compatible release (default selection).
     *
     * @param list<PluginRelease> $releases all published releases of ONE plugin
     */
    public function resolveLatestCompatible(
        array $releases,
        string $coreVersion,
        string $pluginApiVersion,
        ?string $installedVersion = null,
    ): PluginResolution {
        [$pluginId, $sorted, $compatible, $incompatible, $latestOverall] =
            $this->partition($releases, $coreVersion, $pluginApiVersion);

        $latestCompatible = $compatible[0] ?? null;
        $error = null;
        if ($latestCompatible === null) {
            $error = CompatibilityError::pluginNoCompatibleVersion(
                pluginId: $pluginId,
                currentVersion: $installedVersion,
                coreVersion: $coreVersion,
            );
        }

        return new PluginResolution(
            pluginId: $pluginId,
            selected: $latestCompatible,
            latestCompatible: $latestCompatible,
            latestOverall: $latestOverall,
            compatible: $compatible,
            incompatible: $incompatible,
            error: $error,
        );
    }

    /**
     * Resolve a specific requested version, checking compatibility explicitly.
     *
     * @param list<PluginRelease> $releases all published releases of ONE plugin
     */
    public function resolveVersion(
        array $releases,
        string $requestedVersion,
        string $coreVersion,
        string $pluginApiVersion,
        ?string $installedVersion = null,
    ): PluginResolution {
        [$pluginId, $sorted, $compatible, $incompatible, $latestOverall] =
            $this->partition($releases, $coreVersion, $pluginApiVersion);

        $found = null;
        foreach ($sorted as $release) {
            if (SemverHelper::compare($release->version, $requestedVersion) === 0) {
                $found = $release;
                break;
            }
        }

        if ($found === null) {
            $error = new CompatibilityError(
                component: CompatibilityError::COMPONENT_PLUGIN,
                componentId: $pluginId !== '' ? $pluginId : ($releases[0]->id ?? ''),
                currentVersion: $installedVersion,
                targetVersion: $requestedVersion,
                requiredRange: '*',
                blocking: true,
                message: sprintf('Plugin %s has no published version %s.', $pluginId, $requestedVersion),
            );
            return new PluginResolution($pluginId, null, $compatible[0] ?? null, $latestOverall, $compatible, $incompatible, $error);
        }

        $error = $this->compatibilityErrorFor($found, $coreVersion, $pluginApiVersion, $installedVersion);
        return new PluginResolution(
            pluginId: $pluginId,
            selected: $error === null ? $found : null,
            latestCompatible: $compatible[0] ?? null,
            latestOverall: $latestOverall,
            compatible: $compatible,
            incompatible: $incompatible,
            error: $error,
        );
    }

    /**
     * Partition a plugin's releases into (compatible desc, incompatible desc)
     * and find the newest non-blocked release overall.
     *
     * @param list<PluginRelease> $releases
     * @return array{0:string,1:list<PluginRelease>,2:list<PluginRelease>,3:list<PluginRelease>,4:?PluginRelease}
     */
    private function partition(array $releases, string $coreVersion, string $pluginApiVersion): array
    {
        $sorted = $releases;
        usort($sorted, static fn (PluginRelease $a, PluginRelease $b): int => SemverHelper::compare($b->version, $a->version));

        $pluginId = $sorted[0]->id ?? '';
        $compatible = [];
        $incompatible = [];
        $latestOverall = null;
        foreach ($sorted as $release) {
            if (!$release->blocked && $latestOverall === null) {
                $latestOverall = $release;
            }
            if ($this->isCompatible($release, $coreVersion, $pluginApiVersion)) {
                $compatible[] = $release;
            } elseif (!$release->blocked) {
                $incompatible[] = $release;
            }
        }

        return [$pluginId, $sorted, $compatible, $incompatible, $latestOverall];
    }
}
