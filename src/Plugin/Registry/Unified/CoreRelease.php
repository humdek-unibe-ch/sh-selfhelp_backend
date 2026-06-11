<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * A signed, Docker-based **core** release document referenced from the unified
 * registry index via `RegistryIndex::core[].releaseUrl`.
 *
 * Trust boundary (resolved audit finding #9):
 *   - The **SelfHelp Manager** is the FINAL trusted verifier for core releases.
 *     Before it pulls + runs any Docker image it resolves this document,
 *     verifies the Ed25519 signature against its trusted keys AND verifies each
 *     image `digest` at pull time.
 *   - The **CMS/backend** may read this document for ADVISORY preflight only
 *     (e.g. "is target 0.2.0 compatible with my installed plugins?"). The
 *     backend never pulls images and never executes a Docker update, so its
 *     copy of the metadata is advisory. When the backend DOES read a signed
 *     core release through {@see UnifiedRegistryClient::fetchCoreRelease()} it
 *     still verifies the signature so a tampered advisory cannot mislead the
 *     operator — but that verification does not replace the Manager's.
 *
 * Mirrors the shared `CoreRelease` TypeScript interface (`@selfhelp/shared`
 * `distribution.ts`) and the Manager Zod schema.
 */
final class CoreRelease
{
    public const KIND = 'selfhelp-core-release';

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $channel,
        public readonly string $minimumDirectUpgradeFrom,
        public readonly string $pluginApiVersion,
        public readonly CoreImageRef $backend,
        public readonly CoreImageRef $worker,
        public readonly CoreImageRef $scheduler,
        public readonly string $requiredFrontendRange,
        public readonly string $migrationRange,
        public readonly bool $destructive,
        public readonly bool $requiresBackup,
        public readonly bool $manualConfirmationRequired,
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
                '%s: core release "%s@%s" channel must be one of %s.',
                $context,
                $id,
                $version,
                implode('|', RegistryReleaseRef::CHANNELS),
            ));
        }

        $backend = self::requireObject($data, 'backend', $context);
        $worker = self::requireObject($data, 'worker', $context);
        $scheduler = self::requireObject($data, 'scheduler', $context);

        $frontendCompat = self::requireObject($data, 'frontendCompatibility', $context);
        $requiredFrontendRange = self::requireString($frontendCompat, 'requiredFrontendRange', $context . ' frontendCompatibility');

        $database = self::requireObject($data, 'database', $context);

        $security = $data['security'] ?? null;
        if (!is_array($security)) {
            throw new MalformedRegistryException(sprintf('%s: core release "%s@%s" requires a security block.', $context, $id, $version));
        }

        return new self(
            id: $id,
            version: $version,
            channel: $channel,
            minimumDirectUpgradeFrom: self::requireString($data, 'minimumDirectUpgradeFrom', $context),
            pluginApiVersion: self::requireString($data, 'pluginApiVersion', $context),
            backend: CoreImageRef::fromArray($backend, $context . ' backend'),
            worker: CoreImageRef::fromArray($worker, $context . ' worker'),
            scheduler: CoreImageRef::fromArray($scheduler, $context . ' scheduler'),
            requiredFrontendRange: $requiredFrontendRange,
            migrationRange: is_string($database['migrationRange'] ?? null) ? (string) $database['migrationRange'] : '',
            destructive: (bool) ($database['destructive'] ?? false),
            requiresBackup: (bool) ($database['requiresBackup'] ?? true),
            manualConfirmationRequired: (bool) ($database['manualConfirmationRequired'] ?? false),
            security: SignatureBlock::fromArray($security, $context . ' security'),
            blocked: (bool) ($data['blocked'] ?? false),
            raw: $data,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,mixed>
     */
    private static function requireObject(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new MalformedRegistryException(sprintf('%s: "%s" must be an object.', $context, $key));
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
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
