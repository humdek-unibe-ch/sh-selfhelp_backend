<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Security\SignedPayloadBuilder;

/**
 * Validates an extracted `.shplugin` staging directory.
 *
 * Steps:
 *
 *   1. Parse `plugin.json` via `PluginManifestLoader` (canonical schema).
 *   2. Parse `signature.json` → `{keyId, signature, signedPayload}`.
 *   3. Recompute the canonical payload from the manifest + checksums
 *      via `SignedPayloadBuilder` and assert byte-equality with
 *      `signedPayload` — defends against tampering after signing.
 *   4. Verify each entry of `artifacts/SHA256SUMS` against the actual
 *      file contents.
 *   5. Hand the payload to `PluginSignatureVerifier` for Ed25519 +
 *      trusted-key check.
 *
 * Returns a `{manifest, resolvedSource}` tuple ready for the
 * installer.
 */
final class PluginArchiveValidator
{
    /**
     * @param bool $allowComposerScripts Mirrors
     *      `SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS`. When `true`, a
     *      standalone archive whose `backend/package/composer.json`
     *      declares a `scripts` block is accepted. The Messenger
     *      worker still passes `--no-scripts` to every `composer
     *      require`, so the worst the flag does is let a publisher
     *      ship dev-only scripts (`phpstan`, `phpunit`, …) for local
     *      developer ergonomics. Defaults to `false` so production
     *      hosts reject unknown publisher scripts by default. Wired
     *      via DI from the env in `config/services.yaml`; reading the
     *      env directly via `getenv()` does not work because Symfony's
     *      Dotenv only populates `$_ENV` / `$_SERVER`.
     */
    public function __construct(
        private readonly PluginManifestLoader $manifestLoader,
        private readonly SignedPayloadBuilder $signedPayloadBuilder,
        private readonly PluginSignatureVerifier $signatureVerifier,
        private readonly bool $allowComposerScripts = false,
    ) {
    }

    /**
     * Returns a fresh validator backed by a different signature
     * verifier. Used by the inspect-archive trust-helper flow so the
     * per-request override carries the host's `allowComposerScripts`
     * baseline without leaking the readonly property to callers.
     */
    public function withVerifier(PluginSignatureVerifier $verifier): self
    {
        return new self(
            $this->manifestLoader,
            $this->signedPayloadBuilder,
            $verifier,
            $this->allowComposerScripts,
        );
    }

    /**
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    public function validate(string $stagingDir): array
    {
        $manifestPath = $stagingDir . '/plugin.json';
        $signaturePath = $stagingDir . '/signature.json';
        $sumsPath = $stagingDir . '/artifacts/SHA256SUMS';

        $manifest = $this->manifestLoader->loadFromFile($manifestPath);
        $signature = $this->parseSignatureFile($signaturePath);
        $checksums = $this->parseSha256Sums($sumsPath);
        $this->verifyArtifactHashes($stagingDir, $checksums);

        $manifestData = $manifest->toArray();
        $frontend = is_array($manifestData['frontend'] ?? null) ? $manifestData['frontend'] : [];
        $runtimeRaw = $frontend['runtime'] ?? null;
        if (!is_array($runtimeRaw)) {
            throw new PluginArchiveException('plugin.json in archive is missing frontend.runtime.');
        }
        $runtime = $this->assocArray($runtimeRaw);
        $backend = is_array($manifestData['backend'] ?? null) ? $manifestData['backend'] : [];
        $composerRaw = $backend['composer'] ?? null;
        if (!is_array($composerRaw)) {
            throw new PluginArchiveException('plugin.json in archive is missing backend.composer.');
        }
        $composer = $this->assocArray($composerRaw);
        $compat = $manifestData['compatibility'] ?? null;
        if (!is_array($compat)) {
            throw new PluginArchiveException('plugin.json in archive is missing compatibility.');
        }

        // archive.mode controls whether the backend Composer package
        // is resolved from Packagist (connected) or from a path
        // repository pointing at the staged backend/package/ dir
        // (standalone). `present=false` covers handcrafted archives
        // that omit the block; we treat them as connected with
        // composer-packagist install mode and skip the backend slot
        // verification entirely.
        $archiveBlock = $this->readArchiveBlock($manifestData);
        $archiveMode = $archiveBlock['mode'];
        $archiveBackendDir = null;
        if ($archiveMode === 'standalone') {
            $archiveBackendDir = $stagingDir . '/backend/package';
            $this->verifyStandaloneBackend(
                $stagingDir,
                $checksums,
                $manifest,
                $composer,
            );
        }

        // The runtime URLs IN the canonical payload point to the artifact paths
        // INSIDE the archive (the publisher signed those, not the eventual
        // host-served URL). Use the archive-internal paths to reconstruct.
        //
        // CSS is OPTIONAL — `sign.mjs` omits `stylesheetUrl` and
        // `frontendCss` from the canonical payload when the build did
        // not emit a `plugin.css`. Mirror that here based on actual
        // staging-dir presence (and on the SHA256SUMS having an entry),
        // otherwise the recomputed payload would no longer be
        // byte-identical to what the publisher signed.
        $hasCss = is_file($stagingDir . '/artifacts/plugin.css')
            && isset($checksums['artifacts/plugin.css']);

        $payloadInput = [
            'pluginId' => $manifest->getPluginId(),
            'version' => $manifest->getVersion(),
            'composer' => $composer,
            'runtime' => [
                'entrypointUrl' => 'artifacts/plugin.esm.js',
                'format' => is_scalar($runtime['format'] ?? null) ? (string) $runtime['format'] : 'esm',
            ],
            'checksums' => [
                'frontendEsm' => $this->normaliseChecksum($checksums['artifacts/plugin.esm.js'] ?? ''),
            ],
            'compatibility' => $compat,
        ];
        if ($hasCss) {
            $payloadInput['runtime']['stylesheetUrl'] = 'artifacts/plugin.css';
            $payloadInput['checksums']['frontendCss'] = $this->normaliseChecksum($checksums['artifacts/plugin.css']);
        }
        if (isset($runtime['integrity']) && is_string($runtime['integrity']) && $runtime['integrity'] !== '') {
            $payloadInput['runtime']['integrity'] = $runtime['integrity'];
        }
        if (isset($runtime['stylesheetIntegrity']) && is_string($runtime['stylesheetIntegrity']) && $runtime['stylesheetIntegrity'] !== '') {
            $payloadInput['runtime']['stylesheetIntegrity'] = $runtime['stylesheetIntegrity'];
        }

        // Re-inject the archive block so the recomputed canonical
        // payload reproduces the publisher's signed bytes. For
        // standalone archives we recompute the backend packageHash
        // from disk; if the publisher tampered with backend/package/
        // after signing, the recomputed payload diverges and we
        // reject.
        if ($archiveBlock['present']) {
            $archiveInput = ['mode' => $archiveMode];
            if ($archiveMode === 'standalone') {
                $archiveInput['backend'] = [
                    'included' => true,
                    'path' => 'backend/package',
                    'installMode' => $archiveBlock['installMode'],
                    'packageHash' => $this->computeBackendPackageHash($checksums),
                ];
            }
            $payloadInput['archive'] = $archiveInput;
        }

        try {
            $recomputed = $this->signedPayloadBuilder->build($payloadInput);
        } catch (\Throwable $e) {
            throw new PluginArchiveException('Failed to recompute canonical signed payload: ' . $e->getMessage(), 0, $e);
        }

        if (!hash_equals($recomputed, $signature['signedPayload'])) {
            throw new PluginArchiveException(
                'Canonical signed payload mismatch: the archive\'s plugin.json + SHA256SUMS do not reproduce the bytes the publisher signed. The archive was tampered with after signing.',
            );
        }

        try {
            $this->signatureVerifier->verify(
                $manifest->getTrustLevel(),
                $signature['keyId'],
                $signature['signature'],
                $signature['signedPayload'],
                [
                    'requireSignature' => $manifest->getSigningRequired(),
                    'acceptedKeyIds' => $manifest->getSigningAcceptedKeyIds(),
                ],
            );
        } catch (PluginSignatureException $e) {
            throw new PluginArchiveException($e->getMessage(), 0, $e);
        }

        $expectedChecksums = [
            'frontendEsm' => $this->normaliseChecksum($checksums['artifacts/plugin.esm.js'] ?? ''),
        ];
        if ($hasCss) {
            $expectedChecksums['frontendCss'] = $this->normaliseChecksum($checksums['artifacts/plugin.css']);
        }
        $resolved = new ResolvedSource(
            kind: ResolvedSource::KIND_ARCHIVE,
            sourceName: null,
            manifestUrl: null,
            signedPayload: $signature['signedPayload'],
            signature: $signature['signature'],
            keyId: $signature['keyId'],
            expectedChecksums: $expectedChecksums,
            composer: $composer,
            runtime: $runtime,
            archiveStagingDir: $stagingDir,
            archiveMode: $archiveMode,
            archiveBackendDir: $archiveBackendDir,
        );

        return ['manifest' => $manifest, 'resolved' => $resolved];
    }

    /**
     * @return array{keyId:string,signature:string,signedPayload:string}
     */
    private function parseSignatureFile(string $path): array
    {
        if (!is_file($path)) {
            throw new PluginArchiveException('signature.json missing from archive.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PluginArchiveException('signature.json is not readable.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new PluginArchiveException('signature.json is not a JSON object.');
        }
        $keyId = $data['keyId'] ?? null;
        $signature = $data['signature'] ?? null;
        $signedPayload = $data['signedPayload'] ?? null;
        if (!is_string($keyId) || $keyId === '') {
            throw new PluginArchiveException(sprintf('signature.json is missing "%s".', 'keyId'));
        }
        if (!is_string($signature) || $signature === '') {
            throw new PluginArchiveException(sprintf('signature.json is missing "%s".', 'signature'));
        }
        if (!is_string($signedPayload) || $signedPayload === '') {
            throw new PluginArchiveException(sprintf('signature.json is missing "%s".', 'signedPayload'));
        }
        return [
            'keyId' => $keyId,
            'signature' => $signature,
            'signedPayload' => $signedPayload,
        ];
    }

    /**
     * @return array<string,string> relativePath => sha256 hex
     */
    private function parseSha256Sums(string $path): array
    {
        if (!is_file($path)) {
            throw new PluginArchiveException('artifacts/SHA256SUMS missing from archive.');
        }
        $raw = (string) file_get_contents($path);
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Fa-f0-9]{64})\s+(.+)$/', $line, $matches)) {
                throw new PluginArchiveException(sprintf('SHA256SUMS line is malformed: "%s".', $line));
            }
            $hash = strtolower($matches[1]);
            $relative = ltrim($matches[2], './');
            $out[$relative] = $hash;
        }
        if ($out === []) {
            throw new PluginArchiveException('SHA256SUMS is empty.');
        }
        return $out;
    }

    /**
     * Verifies each `<sha256>  <path>` line of
     * `artifacts/SHA256SUMS`. Paths MUST be archive-root-relative and
     * MUST begin with one of the recognised prefixes — `artifacts/`
     * for frontend artifacts or `backend/package/` for the standalone
     * backend slot. The publishing toolchain (`scripts/sign.mjs`,
     * `scripts/build-shplugin.mjs`) produces canonical paths in these
     * forms; accepting any other layout would silently let a forged
     * archive smuggle files outside the recognised staging slots.
     *
     * @param array<string,string> $sums relativePath => sha256 hex
     */
    private function verifyArtifactHashes(string $stagingDir, array $sums): void
    {
        foreach ($sums as $relative => $expected) {
            if (!str_starts_with($relative, 'artifacts/') && !str_starts_with($relative, 'backend/package/')) {
                throw new PluginArchiveException(sprintf(
                    'SHA256SUMS entry "%s" must be archive-root-relative and live under artifacts/ or backend/package/. The canonical .shplugin layout puts every signed file under one of those prefixes.',
                    $relative,
                ));
            }
            if (str_contains($relative, '..')) {
                throw new PluginArchiveException(sprintf(
                    'SHA256SUMS entry "%s" contains ".." segments — rejected to prevent zip-slip / path-traversal smuggling.',
                    $relative,
                ));
            }
            $abs = $stagingDir . '/' . $relative;
            if (!is_file($abs)) {
                throw new PluginArchiveException(sprintf('SHA256SUMS references missing file "%s".', $relative));
            }
            $actual = hash_file('sha256', $abs);
            if ($actual !== $expected) {
                throw new PluginArchiveException(sprintf(
                    'SHA-256 mismatch for "%s": expected %s, got %s.',
                    $relative,
                    $expected,
                    $actual,
                ));
            }
        }
    }

    /**
     * Reads the `archive` block from a manifest array. Returns a
     * normalised descriptor: `{present, mode, installMode}`. Missing
     * blocks (handcrafted archives that bypass the canonical schema)
     * default to `mode=connected` with `installMode=composer-packagist`
     * and `present=false`, which suppresses the archive re-injection
     * step in the canonical payload — preserving byte-equality for
     * legacy fixtures that never carried the block.
     *
     * The schema already enforces enum values; here we tolerate the
     * absence of the block and surface a precise error when `mode`
     * has an unsupported value (defensive against handcrafted
     * archives that bypass the canonical schema).
     *
     * @param array<string,mixed> $manifestData
     * @return array{present: bool, mode: string, installMode: string}
     */
    private function readArchiveBlock(array $manifestData): array
    {
        $archive = $manifestData['archive'] ?? null;
        if (!is_array($archive)) {
            return ['present' => false, 'mode' => 'connected', 'installMode' => 'composer-packagist'];
        }
        $mode = $archive['mode'] ?? null;
        if (!is_string($mode) || ($mode !== 'connected' && $mode !== 'standalone')) {
            throw new PluginArchiveException(sprintf(
                'plugin.json has unsupported archive.mode (expected "connected" or "standalone", got %s).',
                is_string($mode) ? '"' . $mode . '"' : gettype($mode),
            ));
        }
        $installMode = 'composer-packagist';
        if ($mode === 'standalone') {
            $backend = $archive['backend'] ?? null;
            if (!is_array($backend)) {
                throw new PluginArchiveException('plugin.json archive.backend is required when archive.mode="standalone".');
            }
            $rawInstallMode = $backend['installMode'] ?? null;
            if (!is_string($rawInstallMode) || $rawInstallMode === '') {
                throw new PluginArchiveException('plugin.json archive.backend.installMode is required.');
            }
            if ($rawInstallMode !== 'composer-path-repository') {
                throw new PluginArchiveException(sprintf(
                    'plugin.json archive.backend.installMode "%s" is not supported by this host (expected: composer-path-repository).',
                    $rawInstallMode,
                ));
            }
            $installMode = $rawInstallMode;
        }
        return ['present' => true, 'mode' => $mode, 'installMode' => $installMode];
    }

    /**
     * Verifies the staged `backend/package/` slot:
     *
     *   1. Every file on disk under `backend/package/` is listed in
     *      SHA256SUMS (two-way diff catches files appended after
     *      signing — `verifyArtifactHashes()` already covered the
     *      other direction).
     *   2. `composer.json` parses, and its `name` + `version` match
     *      `plugin.json#backend.composer.package` + `plugin.json#version`.
     *      This is the publisher contract enforced at host side.
     *   3. `composer.json` declares no `scripts` block unless an env
     *      allow-list opt-in is set. Composer scripts can run arbitrary
     *      shell on `composer require`; the worker already passes
     *      `--no-scripts`, but rejecting at the manifest layer is
     *      defence-in-depth (the flag could be inverted by accident).
     *
     * @param array<string,string> $checksums archive-root-relative path => sha256 hex
     * @param array<string,mixed>  $manifestComposer plugin.json#backend.composer
     */
    private function verifyStandaloneBackend(
        string $stagingDir,
        array $checksums,
        PluginManifest $manifest,
        array $manifestComposer,
    ): void {
        $backendDir = $stagingDir . '/backend/package';
        if (!is_dir($backendDir)) {
            throw new PluginArchiveException('Standalone archive declared archive.mode="standalone" but backend/package/ directory is missing from the staging dir.');
        }

        // Two-way diff — every file on disk must be in SHA256SUMS.
        // verifyArtifactHashes already enforced the inverse (every line
        // points to a real file). Together they pin the backend tree.
        $onDisk = $this->listBackendFiles($backendDir);
        foreach ($onDisk as $rel) {
            $key = 'backend/package/' . $rel;
            if (!array_key_exists($key, $checksums)) {
                throw new PluginArchiveException(sprintf(
                    'Standalone archive contains backend file "%s" that is not listed in artifacts/SHA256SUMS. Every file under backend/package/ must be signed.',
                    $key,
                ));
            }
        }

        // composer.json contract — name + version must match plugin.json.
        $composerPath = $backendDir . '/composer.json';
        $composerRaw = file_get_contents($composerPath);
        if ($composerRaw === false) {
            throw new PluginArchiveException('Could not read backend/package/composer.json from the staging dir.');
        }
        $composerData = json_decode($composerRaw, true);
        if (!is_array($composerData)) {
            throw new PluginArchiveException('backend/package/composer.json is not a JSON object.');
        }

        $expectedName = is_string($manifestComposer['package'] ?? null) ? (string) $manifestComposer['package'] : '';
        $expectedVersion = $manifest->getVersion();
        $actualName = is_string($composerData['name'] ?? null) ? (string) $composerData['name'] : '';
        $actualVersion = is_string($composerData['version'] ?? null) ? (string) $composerData['version'] : '';

        if ($actualName === '' || $actualName !== $expectedName) {
            throw new PluginArchiveException(sprintf(
                'backend/package/composer.json#name "%s" does not match plugin.json#backend.composer.package "%s". The publisher must keep these two values in sync for standalone archives.',
                $actualName,
                $expectedName,
            ));
        }
        if ($actualVersion === '' || $actualVersion !== $expectedVersion) {
            throw new PluginArchiveException(sprintf(
                'backend/package/composer.json#version "%s" does not match plugin.json#version "%s". The publisher must keep these two values in sync for standalone archives (the host\'s `composer require` constraint comes from plugin.json).',
                $actualVersion,
                $expectedVersion,
            ));
        }

        // Reject composer.json#scripts unless explicitly allow-listed
        // via SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS=1 (wired through
        // DI as `$allowComposerScripts`). Composer scripts can run
        // arbitrary shell on `composer require`. The Messenger worker
        // already passes `--no-scripts`, but layering the rejection
        // here protects against an operator who flips a future flag
        // without realising the implication.
        if (!$this->allowComposerScripts
            && isset($composerData['scripts'])
            && is_array($composerData['scripts'])
            && $composerData['scripts'] !== []
        ) {
            throw new PluginArchiveException(
                'backend/package/composer.json declares a "scripts" block. Composer scripts can execute arbitrary shell on install and are rejected for standalone archives. '
                . 'Remove the "scripts" key from composer.json, or set SELFHELP_PLUGIN_ALLOW_COMPOSER_SCRIPTS=1 to opt in (advanced).',
            );
        }
    }

    /**
     * Recomputes the `archive.backend.packageHash` from the SHA256SUMS
     * entries that live under `backend/package/`. The build script
     * uses the same formula: sort entries by relative path, join
     * "<hex>  <rel>" lines with "\n", sha256 the resulting string.
     *
     * @param array<string,string> $sums archive-root-relative path => sha256 hex
     */
    private function computeBackendPackageHash(array $sums): string
    {
        $relevant = [];
        foreach ($sums as $rel => $hash) {
            if (str_starts_with($rel, 'backend/package/')) {
                $relevant[$rel] = $hash;
            }
        }
        ksort($relevant);
        $lines = [];
        foreach ($relevant as $rel => $hash) {
            $lines[] = sprintf('%s  %s', $hash, $rel);
        }
        return 'sha256-' . hash('sha256', implode("\n", $lines));
    }

    /**
     * Lists every file under the backend/package/ slot as forward-slash
     * relative paths, sorted ascending.
     *
     * @return list<string>
     */
    private function listBackendFiles(string $backendDir): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backendDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $abs = $entry->getPathname();
            $rel = ltrim(substr($abs, strlen($backendDir)), '/\\');
            $rel = str_replace('\\', '/', $rel);
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    private function normaliseChecksum(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $value;
        }
        if (str_starts_with($value, 'sha256-')) {
            return $value;
        }
        if (str_starts_with($value, 'sha256:')) {
            return 'sha256-' . substr($value, 7);
        }
        return 'sha256-' . $value;
    }

    /**
     * Coerce a mixed value (typically a nested decoded JSON object) to a
     * string-keyed array. Integer keys are stringified so the result
     * satisfies array<string, mixed>; runtime values are unchanged.
     *
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private function assocArray(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
