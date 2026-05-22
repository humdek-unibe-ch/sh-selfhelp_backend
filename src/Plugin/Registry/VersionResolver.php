<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Registry;

use App\Entity\Plugin\PluginSource;
use App\Plugin\Versioning\SemverHelper;

/**
 * Picks the best plugin version satisfying a range across every
 * configured registry source.
 *
 * Resolution order:
 *
 *   1. Filter out sources whose channel is not in the operator's
 *      `SELFHELP_PLUGIN_ALLOWED_CHANNELS` (configured via
 *      constructor).
 *   2. Filter versions to those that satisfy the requested range.
 *   3. Sort descending by semver; pick the first match.
 *
 * The resolver returns a structured object including the source name
 * + raw registry entry so the installer can reach for downloads,
 * checksums, signatures, etc.
 */
final class VersionResolver
{
    public function __construct(
        /** @var list<string> Allowed channel codes (`stable`, `beta`, etc). */
        private readonly array $allowedChannels = [PluginSource::CHANNEL_STABLE],
    ) {
    }

    /**
     * @param array<string, array<string,mixed>> $sourceEntries
     *        `sourceName => registry entry` for the plugin id of
     *        interest (typically obtained from
     *        `RegistryClient::fetchAllIndexes()[$pluginId]`).
     *
     * @return array{source: string, version: string, entry: array<string,mixed>}|null
     */
    public function resolve(array $sourceEntries, string $range = '*'): ?array
    {
        $candidates = [];

        foreach ($sourceEntries as $sourceName => $entry) {
            $channel = isset($entry['channel']) ? (string) $entry['channel'] : PluginSource::CHANNEL_STABLE;
            if (!in_array($channel, $this->allowedChannels, true)) {
                continue;
            }

            $versions = $entry['versions'] ?? [];
            if (!is_array($versions)) {
                continue;
            }

            foreach ($versions as $version => $versionEntry) {
                if (!is_string($version) && !is_int($version)) {
                    continue;
                }
                $versionString = (string) $version;
                if (!SemverHelper::satisfies($versionString, $range)) {
                    continue;
                }
                $candidates[] = [
                    'source' => (string) $sourceName,
                    'version' => $versionString,
                    'entry' => is_array($versionEntry) ? $versionEntry : ['version' => $versionString],
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => SemverHelper::compare($b['version'], $a['version'])
        );
        return $candidates[0];
    }
}
