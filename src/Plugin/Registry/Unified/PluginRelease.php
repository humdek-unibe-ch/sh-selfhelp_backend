<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * A signed plugin release document referenced from the unified registry index
 * via `RegistryReleaseRef::releaseUrl`.
 *
 * Mirrors the shared `PluginRelease` TypeScript interface
 * (`@selfhelp/shared` `distribution.ts`) and the Manager Zod schema:
 *
 * ```json
 * {
 *   "kind": "selfhelp-plugin-release",
 *   "id": "sh2-shp-survey-js",
 *   "version": "0.1.0",
 *   "channel": "stable",
 *   "official": true,
 *   "compatibility": { "core": ">=0.1.0 <0.2.0", "pluginApi": ">=0.1.0 <0.2.0" },
 *   "dependencies": { "plugins": [] },
 *   "artifacts": {
 *     "manifestUrl": "https://.../plugin.json",
 *     "archiveUrl":  "https://.../sh2-shp-survey-js-0.1.0.shplugin",
 *     "sha256": "sha256:<hex>"
 *   },
 *   "security": { "signature": "...", "keyId": "prod", "signedPayload": "..." }
 * }
 * ```
 *
 * Naming note (resolved drift): registry release documents express plugin
 * compatibility on TWO axes — `compatibility.core` (the SelfHelp core range)
 * and `compatibility.pluginApi` (the plugin-API range). The author-facing
 * `plugin.json` manifest keeps `compatibility.selfhelp` + top-level
 * `pluginApiVersion`; the publisher maps manifest -> release at build time.
 */
final class PluginRelease
{
    public const KIND = 'selfhelp-plugin-release';

    /**
     * @param list<array{id:string,range:string}> $dependencyPlugins
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $channel,
        public readonly bool $official,
        public readonly string $compatibilityCore,
        public readonly string $compatibilityPluginApi,
        public readonly array $dependencyPlugins,
        public readonly string $manifestUrl,
        public readonly string $archiveUrl,
        public readonly string $sha256,
        public readonly SignatureBlock $security,
        public readonly bool $blocked,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $context): self
    {
        $kind = $data['kind'] ?? null;
        if ($kind !== self::KIND) {
            throw new MalformedRegistryException(sprintf(
                '%s: expected kind "%s", got %s.',
                $context,
                self::KIND,
                is_string($kind) ? '"' . $kind . '"' : gettype($kind),
            ));
        }

        $id = self::requireString($data, 'id', $context);
        $version = self::requireString($data, 'version', $context);
        $channel = $data['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, RegistryReleaseRef::CHANNELS, true)) {
            throw new MalformedRegistryException(sprintf(
                '%s: plugin release "%s@%s" channel must be one of %s.',
                $context,
                $id,
                $version,
                implode('|', RegistryReleaseRef::CHANNELS),
            ));
        }

        $compatibility = $data['compatibility'] ?? null;
        if (!is_array($compatibility)) {
            throw new MalformedRegistryException(sprintf('%s: plugin release "%s@%s" requires a compatibility object.', $context, $id, $version));
        }
        $compatCore = self::requireString($compatibility, 'core', $context . ' compatibility');
        $compatApi = self::requireString($compatibility, 'pluginApi', $context . ' compatibility');

        $artifacts = $data['artifacts'] ?? null;
        if (!is_array($artifacts)) {
            throw new MalformedRegistryException(sprintf('%s: plugin release "%s@%s" requires an artifacts object.', $context, $id, $version));
        }
        $manifestUrl = self::requireString($artifacts, 'manifestUrl', $context . ' artifacts');
        $archiveUrl = self::requireString($artifacts, 'archiveUrl', $context . ' artifacts');
        $sha256 = self::requireString($artifacts, 'sha256', $context . ' artifacts');

        $security = $data['security'] ?? null;
        if (!is_array($security)) {
            throw new MalformedRegistryException(sprintf('%s: plugin release "%s@%s" requires a security block.', $context, $id, $version));
        }

        return new self(
            id: $id,
            version: $version,
            channel: $channel,
            official: (bool) ($data['official'] ?? false),
            compatibilityCore: $compatCore,
            compatibilityPluginApi: $compatApi,
            dependencyPlugins: self::parseDependencies($data),
            manifestUrl: $manifestUrl,
            archiveUrl: $archiveUrl,
            sha256: $sha256,
            security: SignatureBlock::fromArray($security, $context . ' security'),
            blocked: (bool) ($data['blocked'] ?? false),
            raw: $data,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array{id:string,range:string}>
     */
    private static function parseDependencies(array $data): array
    {
        $deps = $data['dependencies'] ?? null;
        if (!is_array($deps)) {
            return [];
        }
        $plugins = $deps['plugins'] ?? null;
        if (!is_array($plugins)) {
            return [];
        }
        $out = [];
        foreach ($plugins as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $depId = $entry['id'] ?? null;
            $range = $entry['range'] ?? null;
            if (is_string($depId) && $depId !== '' && is_string($range) && $range !== '') {
                $out[] = ['id' => $depId, 'range' => $range];
            }
        }
        return $out;
    }

    /**
     * @param array<array-key,mixed> $data
     */
    private static function requireString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be a non-empty string.', $context, $key));
        }
        return $value;
    }
}
