<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Manifest;

use App\Plugin\Archive\PluginArchiveException;
use App\Plugin\Archive\PluginArchiveExtractor;
use App\Plugin\Archive\PluginArchiveValidator;
use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Security\SignedPayloadBuilder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Single normaliser for every plugin install source:
 *
 *   - `registry` — embedded entry from `RegistryClient::fetchAllIndexes()`
 *   - `url` — direct `plugin.json` URL
 *   - `paste` — raw pasted JSON (developer/debug)
 *   - `archive` — uploaded `.shplugin` file
 *
 * Returns `{PluginManifest, ResolvedSource}`. The installer / Messenger
 * handlers don't care which source originated the request.
 *
 * Signature handling:
 *   - registry/url/paste: a `signedPayload + signature + keyId` triple
 *     MUST come from the same source for non-untrusted plugins. The
 *     resolver re-canonicalises and asserts byte-equality just like
 *     the archive validator does.
 *   - archive: the validator does the same checks plus SHA-256 hash
 *     verification.
 */
final class ManifestResolver
{
    public function __construct(
        private readonly PluginManifestLoader $manifestLoader,
        private readonly PluginArchiveExtractor $archiveExtractor,
        private readonly PluginArchiveValidator $archiveValidator,
        private readonly SignedPayloadBuilder $signedPayloadBuilder,
        private readonly PluginSignatureVerifier $signatureVerifier,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    public function resolveArchive(UploadedFile $file): array
    {
        $extracted = $this->archiveExtractor->extract($file);
        return $this->archiveValidator->validate($extracted['stagingDir']);
    }

    /**
     * @param array<string,mixed> $registryEntry
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    public function resolveRegistry(array $registryEntry, string $sourceName): array
    {
        $manifestData = $this->loadManifestFromRegistryEntry($registryEntry);
        $manifest = $this->manifestLoader->loadFromArray($manifestData);
        $resolved = $this->buildResolvedFromCanonical(
            kind: ResolvedSource::KIND_REGISTRY,
            sourceName: $sourceName,
            manifestUrl: isset($registryEntry['manifestUrl']) && is_string($registryEntry['manifestUrl']) ? $registryEntry['manifestUrl'] : null,
            entry: $registryEntry,
            manifest: $manifest,
        );
        return ['manifest' => $manifest, 'resolved' => $resolved];
    }

    /**
     * @param array<string,mixed>|null $registryEntry
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    public function resolveUrl(string $manifestUrl, ?array $registryEntry = null): array
    {
        $manifestData = $this->fetchManifest($manifestUrl);
        $manifest = $this->manifestLoader->loadFromArray($manifestData);
        $entry = is_array($registryEntry) ? $registryEntry : [];
        $resolved = $this->buildResolvedFromCanonical(
            kind: ResolvedSource::KIND_URL,
            sourceName: null,
            manifestUrl: $manifestUrl,
            entry: $entry,
            manifest: $manifest,
        );
        return ['manifest' => $manifest, 'resolved' => $resolved];
    }

    /**
     * @param array<string,mixed> $manifestData
     * @param array<string,mixed>|null $registryEntry optional {signature, signedPayload, keyId, ...}
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    public function resolvePaste(array $manifestData, ?array $registryEntry = null): array
    {
        $manifest = $this->manifestLoader->loadFromArray($manifestData);
        $entry = is_array($registryEntry) ? $registryEntry : [];
        $resolved = $this->buildResolvedFromCanonical(
            kind: ResolvedSource::KIND_PASTE,
            sourceName: null,
            manifestUrl: null,
            entry: $entry,
            manifest: $manifest,
        );
        return ['manifest' => $manifest, 'resolved' => $resolved];
    }

    /**
     * @param array<string,mixed> $registryEntry
     * @return array<string,mixed>
     */
    private function loadManifestFromRegistryEntry(array $registryEntry): array
    {
        if (isset($registryEntry['manifest']) && is_array($registryEntry['manifest'])) {
            return $this->assocArray($registryEntry['manifest']);
        }
        if (isset($registryEntry['manifestUrl']) && is_string($registryEntry['manifestUrl']) && $registryEntry['manifestUrl'] !== '') {
            return $this->fetchManifest($registryEntry['manifestUrl']);
        }
        throw new \RuntimeException('Registry entry must include `manifest` or `manifestUrl`.');
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchManifest(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'SelfHelp-Plugin-Manager/1.0',
            ],
            'timeout' => 15,
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Manifest fetch failed: %s returned HTTP %d.', $url, $status));
        }
        $body = $response->getContent(false);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Manifest fetch failed: %s returned invalid JSON.', $url));
        }
        return $this->assocArray($data);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function buildResolvedFromCanonical(
        string $kind,
        ?string $sourceName,
        ?string $manifestUrl,
        array $entry,
        PluginManifest $manifest,
    ): ResolvedSource {
        $signedPayload = $this->stringOrNull($entry, 'signedPayload');
        $signature = $this->stringOrNull($entry, 'signature');
        $keyId = $this->stringOrNull($entry, 'keyId');
        $trustLevel = $manifest->getTrustLevel();

        $manifestData = $manifest->toArray();
        $frontend = $this->assocArray($manifestData['frontend'] ?? null);
        $runtime = $this->assocArray($frontend['runtime'] ?? null);
        $backend = $this->assocArray($manifestData['backend'] ?? null);
        $composer = $this->assocArray($backend['composer'] ?? null);
        $archive = (isset($manifestData['archive']) && is_array($manifestData['archive']))
            ? $manifestData['archive']
            : ['mode' => 'connected'];
        $checksums = $this->assocArray($entry['checksums'] ?? null);
        $resolvedRuntime = $runtime;
        $resolvedComposer = $composer;

        if ($signedPayload !== null && $signature !== null && $keyId !== null) {
            $registryRuntime = $this->assocArray($entry['runtime'] ?? null);
            $registryComposer = $this->assocArray($entry['composer'] ?? null);

            $payloadInput = [
                'pluginId' => $manifest->getPluginId(),
                'version' => $manifest->getVersion(),
                'composer' => $registryComposer !== [] ? $registryComposer : $composer,
                'runtime' => [
                    'entrypointUrl' => $this->scalarString($registryRuntime['entrypointUrl'] ?? ''),
                    'format' => $this->scalarString($registryRuntime['format'] ?? ($runtime['format'] ?? 'esm')),
                ],
                'checksums' => [
                    'frontendEsm' => $this->scalarString($checksums['frontendEsm'] ?? ''),
                ],
                'compatibility' => isset($manifestData['compatibility']) && is_array($manifestData['compatibility']) ? $manifestData['compatibility'] : [],
                'archive' => $archive,
            ];
            if (isset($registryRuntime['stylesheetUrl']) && is_string($registryRuntime['stylesheetUrl']) && $registryRuntime['stylesheetUrl'] !== '') {
                $payloadInput['runtime']['stylesheetUrl'] = $registryRuntime['stylesheetUrl'];
            }
            if (isset($registryRuntime['integrity']) && is_string($registryRuntime['integrity']) && $registryRuntime['integrity'] !== '') {
                $payloadInput['runtime']['integrity'] = $registryRuntime['integrity'];
            }
            if (isset($registryRuntime['stylesheetIntegrity']) && is_string($registryRuntime['stylesheetIntegrity']) && $registryRuntime['stylesheetIntegrity'] !== '') {
                $payloadInput['runtime']['stylesheetIntegrity'] = $registryRuntime['stylesheetIntegrity'];
            }
            if (isset($checksums['frontendCss']) && is_string($checksums['frontendCss']) && $checksums['frontendCss'] !== '') {
                $payloadInput['checksums']['frontendCss'] = $checksums['frontendCss'];
            }
            try {
                $recomputed = $this->signedPayloadBuilder->build($payloadInput);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to recompute canonical signed payload: ' . $e->getMessage(), 0, $e);
            }
            if (!hash_equals($recomputed, $signedPayload)) {
                throw new \RuntimeException(
                    'Canonical signed payload mismatch: the registry entry or manifest do not reproduce the bytes the publisher signed. Refusing install.',
                );
            }

            try {
                $this->signatureVerifier->verify(
                    $trustLevel,
                    $keyId,
                    $signature,
                    $signedPayload,
                    $this->manifestSigningPolicy($manifest),
                );
            } catch (PluginSignatureException $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }

            if ($registryComposer !== []) {
                $resolvedComposer = $this->mergeResolvedComposer($composer, $registryComposer);
            }
            if ($registryRuntime !== []) {
                $resolvedRuntime = $this->mergeResolvedRuntime($runtime, $registryRuntime);
            }
        } else {
            // No signature attached. Let the verifier decide whether the
            // current trustLevel + env policy allows that.
            $this->signatureVerifier->verify(
                $trustLevel,
                null,
                null,
                null,
                $this->manifestSigningPolicy($manifest),
            );
            $signedPayload = $signedPayload ?? '';
            $signature = $signature ?? '';
            $keyId = $keyId ?? '';
        }

        $expectedChecksums = [];
        foreach (['frontendEsm', 'frontendCss'] as $k) {
            if (isset($checksums[$k]) && is_string($checksums[$k]) && $checksums[$k] !== '') {
                $expectedChecksums[$k] = $checksums[$k];
            }
        }

        return new ResolvedSource(
            kind: $kind,
            sourceName: $sourceName,
            manifestUrl: $manifestUrl,
            signedPayload: $signedPayload,
            signature: $signature,
            keyId: $keyId,
            expectedChecksums: $expectedChecksums,
            composer: $resolvedComposer,
            runtime: $resolvedRuntime,
            archiveStagingDir: null,
        );
    }

    /**
     * @param array<string,mixed> $arr
     */
    private function stringOrNull(array $arr, string $key): ?string
    {
        $v = $arr[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Coerce a mixed value (typically a nested decoded JSON object) to a
     * string-keyed array. Non-arrays become an empty array; integer keys
     * are stringified so the result satisfies array<string, mixed>.
     *
     * @return array<string,mixed>
     */
    private function assocArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * Coerce a mixed value to a string, mirroring a plain `(string)` cast
     * for the scalar values these canonical-payload fields receive.
     */
    private function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Preserve manifest-only runtime fields like `entrypoint` /
     * `devEntrypointUrl`, but override install-critical fields from the
     * signed registry/runtime payload.
     *
     * @param array<string,mixed> $manifestRuntime
     * @param array<string,mixed> $registryRuntime
     * @return array<string,mixed>
     */
    private function mergeResolvedRuntime(array $manifestRuntime, array $registryRuntime): array
    {
        $resolved = $manifestRuntime;

        if (isset($registryRuntime['entrypointUrl']) && is_string($registryRuntime['entrypointUrl']) && $registryRuntime['entrypointUrl'] !== '') {
            $resolved['entrypointUrl'] = $registryRuntime['entrypointUrl'];
        }
        if (isset($registryRuntime['stylesheetUrl']) && is_string($registryRuntime['stylesheetUrl']) && $registryRuntime['stylesheetUrl'] !== '') {
            $resolved['stylesheetUrl'] = $registryRuntime['stylesheetUrl'];
        }
        foreach (['format', 'integrity', 'stylesheetIntegrity'] as $key) {
            if (isset($registryRuntime[$key]) && is_string($registryRuntime[$key]) && $registryRuntime[$key] !== '') {
                $resolved[$key] = $registryRuntime[$key];
            }
        }

        return $resolved;
    }

    /**
     * Preserve manifest-only composer fields like `repository` when an
     * older registry entry only signed/published `{package, version}`.
     *
     * @param array<string,mixed> $manifestComposer
     * @param array<string,mixed> $registryComposer
     * @return array<string,mixed>
     */
    private function mergeResolvedComposer(array $manifestComposer, array $registryComposer): array
    {
        $resolved = $manifestComposer;

        foreach (['package', 'version'] as $key) {
            if (isset($registryComposer[$key]) && is_string($registryComposer[$key]) && $registryComposer[$key] !== '') {
                $resolved[$key] = $registryComposer[$key];
            }
        }

        if (isset($registryComposer['repository']) && is_array($registryComposer['repository'])) {
            $resolved['repository'] = $registryComposer['repository'];
        }

        return $resolved;
    }

    /**
     * @return array{requireSignature: bool, acceptedKeyIds: list<string>}
     */
    private function manifestSigningPolicy(PluginManifest $manifest): array
    {
        return [
            'requireSignature' => $manifest->getSigningRequired(),
            'acceptedKeyIds' => $manifest->getSigningAcceptedKeyIds(),
        ];
    }
}
