<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * A single release reference inside the unified `registry.json` index.
 *
 * The index publishes ONE ref per (component, version) — so a plugin with
 * three versions appears as three `plugins[]` refs. Each ref points at a
 * standalone, signed release document via `releaseUrl`.
 *
 * Mirrors the shared `RegistryReleaseRef` TypeScript interface
 * (`@selfhelp/shared` `distribution.ts`) and the Manager Zod schema.
 */
final class RegistryReleaseRef
{
    /** Canonical release channels (matches `@selfhelp/shared` `ReleaseChannel`). */
    public const CHANNELS = ['stable', 'beta', 'nightly'];

    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $channel,
        public readonly string $releaseUrl,
        public readonly bool $blocked = false,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data, string $context): self
    {
        $id = $data['id'] ?? null;
        $version = $data['version'] ?? null;
        $channel = $data['channel'] ?? null;
        $releaseUrl = $data['releaseUrl'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new MalformedRegistryException(sprintf('%s: release ref "id" must be a non-empty string.', $context));
        }
        if (!is_string($version) || $version === '') {
            throw new MalformedRegistryException(sprintf('%s: release ref "%s" version must be a non-empty string.', $context, $id));
        }
        if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)) {
            throw new MalformedRegistryException(sprintf(
                '%s: release ref "%s@%s" channel must be one of %s (got %s).',
                $context,
                $id,
                $version,
                implode('|', self::CHANNELS),
                is_string($channel) ? $channel : gettype($channel),
            ));
        }
        if (!is_string($releaseUrl) || $releaseUrl === '') {
            throw new MalformedRegistryException(sprintf('%s: release ref "%s@%s" releaseUrl must be a non-empty string.', $context, $id, $version));
        }

        return new self(
            id: $id,
            version: $version,
            channel: $channel,
            releaseUrl: $releaseUrl,
            blocked: (bool) ($data['blocked'] ?? false),
        );
    }
}
