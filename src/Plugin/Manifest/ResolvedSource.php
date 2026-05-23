<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Manifest;

/**
 * Immutable description of a fully-resolved plugin install source.
 *
 * `ManifestResolver` turns any of the four install sources (`registry`,
 * `url`, `paste`, `archive`) into the same shape so every downstream
 * code path (installer, updater, Messenger handlers, lock-file writer,
 * promoter) treats them identically.
 *
 *   - `kind`              one of registry | url | paste | archive
 *   - `sourceName`        registry name (when applicable) or null
 *   - `manifestUrl`       remote manifest URL (registry/url) or null
 *   - `signedPayload`     canonical bytes that were signed by CI
 *   - `signature`         base64 Ed25519 detached signature
 *   - `keyId`             trusted-key identifier
 *   - `expectedChecksums` map of artifact → sha256 hex (sourced from
 *                         the canonical signedPayload's `checksums`)
 *   - `composer`          composer coordinates from the canonical
 *                         signedPayload (the canonical record, NOT the
 *                         raw manifest — defends against publishers
 *                         who forget to align the two)
 *   - `runtime`           runtime URL block from canonical payload
 *   - `archiveStagingDir` extracted .shplugin staging dir or null
 *   - `archiveMode`       Phase 2a — "connected" (default) or
 *                         "standalone". Standalone archives carry the
 *                         backend Composer package under backend/package/
 *                         and the install handler resolves it via a
 *                         Composer path repository instead of Packagist.
 *   - `archiveBackendDir` absolute path to the staged backend/package/
 *                         dir when archiveMode=standalone; null otherwise.
 *                         Promoted by PluginArchivePromoter into the
 *                         durable installed/ location at install time.
 *
 * Backwards compatibility: there is no fallback shape. Sources that
 * cannot produce a full `ResolvedSource` are rejected at the resolver
 * boundary so the installer never has to second-guess.
 */
final class ResolvedSource
{
    public const KIND_REGISTRY = 'registry';
    public const KIND_URL = 'url';
    public const KIND_PASTE = 'paste';
    public const KIND_ARCHIVE = 'archive';

    public const ARCHIVE_MODE_CONNECTED = 'connected';
    public const ARCHIVE_MODE_STANDALONE = 'standalone';

    /**
     * @param array<string,string> $expectedChecksums
     * @param array<string,mixed>  $composer
     * @param array<string,mixed>  $runtime
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $sourceName,
        public readonly ?string $manifestUrl,
        public readonly string $signedPayload,
        public readonly string $signature,
        public readonly string $keyId,
        public readonly array $expectedChecksums,
        public readonly array $composer,
        public readonly array $runtime,
        public readonly ?string $archiveStagingDir = null,
        public readonly string $archiveMode = self::ARCHIVE_MODE_CONNECTED,
        public readonly ?string $archiveBackendDir = null,
    ) {
    }
}
