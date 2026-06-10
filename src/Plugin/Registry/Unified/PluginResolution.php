<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * Immutable outcome of resolving the published versions of ONE plugin against
 * a target SelfHelp core + plugin-API version.
 *
 * Carries everything the Available-Plugins UI and the install/update flow need:
 *
 *   - `selected`         the release the resolver chose (newest compatible by
 *                        default, or the requested version) — null when nothing
 *                        compatible / the requested version is incompatible;
 *   - `latestCompatible` newest version that runs on the target core/API;
 *   - `latestOverall`    newest published (non-blocked) version regardless of
 *                        compatibility — lets the UI distinguish "latest
 *                        overall" from "latest compatible";
 *   - `compatible`       all compatible releases, newest first;
 *   - `incompatible`     all non-blocked but incompatible releases, newest first;
 *   - `error`            standardized {@see CompatibilityError} when the
 *                        selection is blocked / impossible, else null.
 */
final class PluginResolution
{
    /**
     * @param list<PluginRelease> $compatible
     * @param list<PluginRelease> $incompatible
     */
    public function __construct(
        public readonly string $pluginId,
        public readonly ?PluginRelease $selected,
        public readonly ?PluginRelease $latestCompatible,
        public readonly ?PluginRelease $latestOverall,
        public readonly array $compatible,
        public readonly array $incompatible,
        public readonly ?CompatibilityError $error,
    ) {
    }

    public function hasCompatibleVersion(): bool
    {
        return $this->latestCompatible !== null;
    }

    /**
     * True when a newer version exists overall than the newest compatible one —
     * i.e. the plugin is "stuck" on an older compatible version because the
     * newest published version does not run on this core.
     */
    public function newerExistsButIncompatible(): bool
    {
        if ($this->latestOverall === null || $this->latestCompatible === null) {
            return $this->latestOverall !== null && $this->latestCompatible === null;
        }
        return \App\Plugin\Versioning\SemverHelper::compare(
            $this->latestOverall->version,
            $this->latestCompatible->version,
        ) > 0;
    }

    /** @return list<string> */
    public function compatibleVersions(): array
    {
        return array_map(static fn (PluginRelease $r): string => $r->version, $this->compatible);
    }

    /** @return list<string> */
    public function incompatibleVersions(): array
    {
        return array_map(static fn (PluginRelease $r): string => $r->version, $this->incompatible);
    }
}
