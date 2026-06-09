<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Consumes the unified `registry.json` the way the Manager does, but for the
 * CMS/backend half of the contract: it reads `RegistryIndex.plugins[]` refs,
 * follows each `releaseUrl` to a signed {@see PluginRelease} document, verifies
 * the Ed25519 signature against the host's trusted keys, and (for install)
 * downloads + checksum-verifies the referenced `.shplugin` artifact.
 *
 * This is the backend sibling of `@shm/registry`'s `RegistryClient`. The two
 * verify the SAME signed release documents using a byte-identical canonical
 * form ({@see CanonicalJson} <-> `@shm/registry` `canonicalize`).
 *
 * The client never mutates state: callers decide what to cache/install.
 */
final class UnifiedRegistryClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PluginSignatureVerifier $signatureVerifier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetch + parse the unified registry index from a `registry.json` URL.
     *
     * @param array<string,string> $headers
     */
    public function fetchIndex(string $registryJsonUrl, array $headers = []): RegistryIndex
    {
        $decoded = $this->fetchJson($registryJsonUrl, $headers);
        return RegistryIndex::fromArray($decoded, sprintf('registry index (%s)', $registryJsonUrl));
    }

    /**
     * Fetch all plugin releases referenced by the index, grouped by plugin id,
     * version-sorted descending. Individual release documents that fail to
     * fetch/parse/verify are logged and skipped so one broken release does not
     * hide the rest of the catalogue from the Available list. Use
     * {@see fetchPluginRelease()} on an explicit install request to surface a
     * hard error instead.
     *
     * @param array<string,string> $headers
     * @return array<string, list<PluginRelease>>
     */
    public function fetchPluginReleases(RegistryIndex $index, array $headers = []): array
    {
        $byId = [];
        foreach ($index->pluginRefsById() as $pluginId => $refs) {
            $releases = [];
            foreach ($refs as $ref) {
                try {
                    $releases[] = $this->fetchPluginRelease($index->resolveUrl($ref->releaseUrl), $headers, $ref);
                } catch (\Throwable $e) {
                    $this->logger->warning('Unified registry: skipping unreadable plugin release', [
                        'plugin' => $pluginId,
                        'version' => $ref->version,
                        'releaseUrl' => $ref->releaseUrl,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
            if ($releases !== []) {
                $byId[$pluginId] = $releases;
            }
        }
        return $byId;
    }

    /**
     * Fetch + parse + signature-verify ONE plugin release document.
     *
     * When `$ref` is supplied, the release document's id/version MUST match the
     * index ref so a tampered index cannot point a ref at an unrelated release.
     *
     * @param array<string,string> $headers
     */
    public function fetchPluginRelease(string $releaseUrl, array $headers = [], ?RegistryReleaseRef $ref = null): PluginRelease
    {
        $decoded = $this->fetchJson($releaseUrl, $headers);
        $release = PluginRelease::fromArray($decoded, sprintf('plugin release (%s)', $releaseUrl));

        if ($ref !== null) {
            if ($release->id !== $ref->id || $release->version !== $ref->version) {
                throw new MalformedRegistryException(sprintf(
                    'Registry ref "%s@%s" points at a release document for "%s@%s" (%s).',
                    $ref->id,
                    $ref->version,
                    $release->id,
                    $release->version,
                    $releaseUrl,
                ));
            }
        }

        $this->verifyReleaseSignature($release);
        return $release;
    }

    /**
     * Fetch + parse + signature-verify ONE core release document.
     *
     * ADVISORY ONLY at the CMS boundary: the backend never pulls images and
     * never runs a Docker update, so this metadata is for preflight display /
     * plugin-compatibility checks. The SelfHelp Manager remains the trusted
     * verifier that re-resolves this document, verifies image digests, and
     * executes the update (see {@see CoreRelease}). We still verify the
     * Ed25519 signature here so a tampered advisory cannot mislead the operator.
     *
     * @param array<string,string> $headers
     */
    public function fetchCoreRelease(string $releaseUrl, array $headers = [], ?RegistryReleaseRef $ref = null): CoreRelease
    {
        $decoded = $this->fetchJson($releaseUrl, $headers);
        $release = CoreRelease::fromArray($decoded, sprintf('core release (%s)', $releaseUrl));

        if ($ref !== null && ($release->id !== $ref->id || $release->version !== $ref->version)) {
            throw new MalformedRegistryException(sprintf(
                'Registry ref "%s@%s" points at a core release document for "%s@%s" (%s).',
                $ref->id,
                $ref->version,
                $release->id,
                $release->version,
                $releaseUrl,
            ));
        }

        // Core releases are Manager-signed and always treated as "official"
        // (no untrusted bypass for a release pulled from the unified catalogue).
        $this->verifySignedDocument('core release', $release->id, $release->version, $release->security, $release->raw, true);
        return $release;
    }

    /**
     * Fetch the security-advisory feed referenced by the index `advisoriesUrl`.
     *
     * The advisory feed is an INFORMATIONAL document, not a signed release: it
     * is fetched + JSON-decoded but not Ed25519-verified (it carries no security
     * block). Returns null when the index declares no advisory feed. The caller
     * (system advisory service) fails soft on transport errors.
     *
     * @param array<string,string> $headers
     * @return array<string,mixed>|null
     */
    public function fetchAdvisoryFeed(RegistryIndex $index, array $headers = []): ?array
    {
        if ($index->advisoriesUrl === null) {
            return null;
        }
        return $this->fetchJson($index->resolveUrl($index->advisoriesUrl), $headers);
    }

    /**
     * Verify the Ed25519 signature of a plugin release document against the
     * host's trusted keys. The signed bytes are `security.signedPayload` when
     * present, otherwise the canonical form of the release with its `security`
     * block removed (matches the Manager `verifyReleaseSignature`).
     *
     * @throws MalformedRegistryException when the signature does not verify.
     */
    public function verifyReleaseSignature(PluginRelease $release): void
    {
        // Registry plugin releases are always signed; map `official` -> the
        // strict "official" trust level and everything else to "reviewed" so
        // the verifier requires a valid signature in every case.
        $this->verifySignedDocument('plugin release', $release->id, $release->version, $release->security, $release->raw, $release->official);
    }

    /**
     * Shared Ed25519 verification for both plugin and core release documents.
     * The signed bytes are `security.signedPayload` when present, otherwise the
     * canonical form of the document with its `security` block removed (matches
     * the Manager `verifyReleaseSignature`). `$official` selects the strict
     * "official" trust level; anything else maps to "reviewed" — both require a
     * valid signature, neither allows the untrusted bypass.
     *
     * @param array<string,mixed> $raw
     * @throws MalformedRegistryException when the signature does not verify.
     */
    private function verifySignedDocument(string $kind, string $id, string $version, SignatureBlock $security, array $raw, bool $official): void
    {
        $payload = $security->signedPayload;
        if ($payload === null) {
            $clone = $raw;
            unset($clone['security']);
            $payload = CanonicalJson::encode($clone);
        }

        if ($security->signedPayloadSha256 !== null) {
            $expected = strtolower(preg_replace('/^sha256:/i', '', $security->signedPayloadSha256) ?? '');
            $actual = hash('sha256', $payload);
            if ($expected !== $actual) {
                throw new MalformedRegistryException(sprintf(
                    '%s "%s@%s": signedPayloadSha256 does not match the canonical payload.',
                    ucfirst($kind),
                    $id,
                    $version,
                ));
            }
        }

        $trustLevel = $official ? 'official' : 'reviewed';
        try {
            $this->signatureVerifier->verify(
                $trustLevel,
                $security->keyId,
                $security->signature,
                $payload,
            );
        } catch (PluginSignatureException $e) {
            throw new MalformedRegistryException(sprintf(
                '%s "%s@%s" signature verification failed: %s',
                ucfirst($kind),
                $id,
                $version,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * Download the `.shplugin` artifact for a release into `$destinationPath`
     * and verify it against `artifacts.sha256`. Returns the number of bytes
     * written. The downstream archive validator re-checks the archive's own
     * internal SHA256SUMS + canonical signed payload.
     *
     * @param array<string,string> $headers
     */
    public function downloadArchive(PluginRelease $release, string $destinationPath, string $resolvedArchiveUrl, array $headers = []): int
    {
        $bytes = $this->fetchRaw($resolvedArchiveUrl, $headers);
        $expected = strtolower(preg_replace('/^sha256:/i', '', $release->sha256) ?? '');
        $actual = hash('sha256', $bytes);
        if ($expected === '' || $expected !== $actual) {
            throw new MalformedRegistryException(sprintf(
                'Plugin release "%s@%s": downloaded archive sha256 %s does not match the registry-declared %s.',
                $release->id,
                $release->version,
                $actual,
                $expected !== '' ? $expected : '(missing)',
            ));
        }
        $written = @file_put_contents($destinationPath, $bytes);
        if ($written === false) {
            throw new \RuntimeException(sprintf('Failed to write downloaded plugin archive to %s.', $destinationPath));
        }
        return $written;
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function fetchJson(string $url, array $headers): array
    {
        $body = $this->fetchRaw($url, $headers + ['Accept' => 'application/json']);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new MalformedRegistryException(sprintf('Registry document %s is not a JSON object.', $url));
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    /**
     * @param array<string,string> $headers
     */
    private function fetchRaw(string $url, array $headers): string
    {
        $headers = $headers + ['User-Agent' => 'SelfHelp-Unified-Registry/1.0'];
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new MalformedRegistryException(sprintf('Registry document %s returned HTTP %d.', $url, $status));
            }
            return $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Transport error fetching %s: %s', $url, $e->getMessage()), 0, $e);
        }
    }
}
