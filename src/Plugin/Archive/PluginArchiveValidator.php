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
    public function __construct(
        private readonly PluginManifestLoader $manifestLoader,
        private readonly SignedPayloadBuilder $signedPayloadBuilder,
        private readonly PluginSignatureVerifier $signatureVerifier,
    ) {
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
        $runtime = $manifestData['frontend']['runtime'] ?? null;
        if (!is_array($runtime)) {
            throw new PluginArchiveException('plugin.json in archive is missing frontend.runtime.');
        }
        $composer = $manifestData['backend']['composer'] ?? null;
        if (!is_array($composer)) {
            throw new PluginArchiveException('plugin.json in archive is missing backend.composer.');
        }
        $compat = $manifestData['compatibility'] ?? null;
        if (!is_array($compat)) {
            throw new PluginArchiveException('plugin.json in archive is missing compatibility.');
        }

        // The runtime URLs IN the canonical payload point to the artifact paths
        // INSIDE the archive (the publisher signed those, not the eventual
        // host-served URL). Use the archive-internal paths to reconstruct.
        $payloadInput = [
            'pluginId' => $manifest->getPluginId(),
            'version' => $manifest->getVersion(),
            'composer' => $composer,
            'runtime' => [
                'entrypointUrl' => 'artifacts/plugin.esm.js',
                'stylesheetUrl' => 'artifacts/plugin.css',
                'format' => (string) ($runtime['format'] ?? 'esm'),
            ],
            'checksums' => [
                'frontendEsm' => $this->normaliseChecksum($checksums['artifacts/plugin.esm.js'] ?? ''),
                'frontendCss' => $this->normaliseChecksum($checksums['artifacts/plugin.css'] ?? ''),
            ],
            'compatibility' => $compat,
        ];
        if (isset($runtime['integrity']) && is_string($runtime['integrity']) && $runtime['integrity'] !== '') {
            $payloadInput['runtime']['integrity'] = $runtime['integrity'];
        }
        if (isset($runtime['stylesheetIntegrity']) && is_string($runtime['stylesheetIntegrity']) && $runtime['stylesheetIntegrity'] !== '') {
            $payloadInput['runtime']['stylesheetIntegrity'] = $runtime['stylesheetIntegrity'];
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

        $resolved = new ResolvedSource(
            kind: ResolvedSource::KIND_ARCHIVE,
            sourceName: null,
            manifestUrl: null,
            signedPayload: $signature['signedPayload'],
            signature: $signature['signature'],
            keyId: $signature['keyId'],
            expectedChecksums: [
                'frontendEsm' => $this->normaliseChecksum($checksums['artifacts/plugin.esm.js'] ?? ''),
                'frontendCss' => $this->normaliseChecksum($checksums['artifacts/plugin.css'] ?? ''),
            ],
            composer: $composer,
            runtime: $runtime,
            archiveStagingDir: $stagingDir,
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
        foreach (['keyId', 'signature', 'signedPayload'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new PluginArchiveException(sprintf('signature.json is missing "%s".', $required));
            }
        }
        return [
            'keyId' => (string) $data['keyId'],
            'signature' => (string) $data['signature'],
            'signedPayload' => (string) $data['signedPayload'],
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
     * MUST begin with `artifacts/` — the publishing toolchain
     * (`scripts/sign.mjs`) produces canonical paths, and accepting any
     * other layout would silently let a forged archive smuggle files
     * outside the artifacts/ tree.
     *
     * @param array<string,string> $sums relativePath => sha256 hex
     */
    private function verifyArtifactHashes(string $stagingDir, array $sums): void
    {
        foreach ($sums as $relative => $expected) {
            if (!str_starts_with($relative, 'artifacts/')) {
                throw new PluginArchiveException(sprintf(
                    'SHA256SUMS entry "%s" must be archive-root-relative and live under artifacts/ (got "%s"). The canonical .shplugin layout puts every signed artifact under artifacts/.',
                    $relative,
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
}
