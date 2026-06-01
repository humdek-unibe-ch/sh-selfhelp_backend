<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a published plugin runtime bundle (plugin.esm.js + optional
 * plugin.css + all code-split chunks) from an absolute https URL into
 * `public/plugin-artifacts/<id>-<version>/`, verifies SHA-256 hashes
 * against the signed canonical payload + the sibling `SHA256SUMS`
 * manifest, and returns the host-relative URLs the frontend can
 * `import()`.
 *
 * Trust model:
 *   1. The signed canonical payload pins `checksums.frontendEsm`
 *      (the entry's SHA-256) and optionally `checksums.frontendCss`.
 *      These are verified directly against the downloaded bytes.
 *   2. The sibling `SHA256SUMS` file (published alongside the entry)
 *      lists every artifact's SHA-256. It is "anchored" to the signed
 *      payload by checking that its own `plugin.esm.js` line matches
 *      `checksums.frontendEsm`. Once anchored, every other listed
 *      chunk inherits trust — equivalent to how `PluginArchiveValidator`
 *      anchors `.shplugin`'s in-archive `SHA256SUMS` to the same
 *      canonical payload during archive validation.
 *   3. Each chunk is downloaded and its SHA-256 is verified against
 *      the value listed in `SHA256SUMS`. Mismatches abort the install.
 *
 * This is the cousin of {@see PluginArchivePromoter}: archive installs
 * promote runtime files out of a `.shplugin` staging dir, while
 * `registry` / `url` installs promote them by HTTP fetch. Both routes
 * end at the same on-disk location so the host frontend always loads
 * plugins (and every code-split chunk) from the same origin as the
 * CMS API — required because plugin bundles import host-only paths
 * like `/api/plugins/runtime-shim/...` which only resolve when
 * same-origin with the SelfHelp host. Chunks are loaded by Vite at
 * runtime via dynamic `import('./chunk.js')` calls that resolve
 * relative to the entry's URL, so they must live in the same dir.
 */
final class PluginRuntimeArtifactFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Fetch the runtime bundle, optional stylesheet, and every
     * code-split chunk listed in the sibling `SHA256SUMS` manifest
     * declared by the signed payload's `runtime` block and promote
     * them into `public/plugin-artifacts/<id>-<version>/`.
     *
     * @param array<string,mixed>  $resolvedRuntime    canonical runtime block from ResolvedSource->runtime
     * @param array<string,string> $expectedChecksums  sha256 map from ResolvedSource->expectedChecksums
     * @return array{
     *     entrypointWebPath: string,
     *     stylesheetWebPath: ?string,
     *     downloadedEntrypoint: bool,
     *     downloadedStylesheet: bool,
     *     downloadedChunks: list<string>
     * }
     */
    public function fetchAndPromote(
        string $pluginId,
        string $version,
        array $resolvedRuntime,
        array $expectedChecksums,
    ): array {
        $entrypointUrl = isset($resolvedRuntime['entrypointUrl']) && is_string($resolvedRuntime['entrypointUrl'])
            ? $resolvedRuntime['entrypointUrl']
            : '';
        if ($entrypointUrl === '' || !$this->isAbsoluteHttpUrl($entrypointUrl)) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" v%s: runtime entrypointUrl must be an absolute https URL to download (got %s).',
                $pluginId,
                $version,
                $entrypointUrl === '' ? '<empty>' : $entrypointUrl,
            ));
        }
        $expectedEsm = $this->normaliseSha256($expectedChecksums['frontendEsm'] ?? null);
        if ($expectedEsm === null) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" v%s: cannot verify runtime entrypoint because the signed payload does not declare checksums.frontendEsm.',
                $pluginId,
                $version,
            ));
        }

        $targetDir = $this->publicDir($pluginId, $version);
        $this->filesystem->mkdir($targetDir, 0775);

        $entrypointFile = $targetDir . DIRECTORY_SEPARATOR . 'plugin.esm.js';
        $this->downloadVerified($entrypointUrl, $entrypointFile, $expectedEsm, 'plugin.esm.js');

        $stylesheetUrl = isset($resolvedRuntime['stylesheetUrl']) && is_string($resolvedRuntime['stylesheetUrl'])
            ? $resolvedRuntime['stylesheetUrl']
            : '';
        $stylesheetWebPath = null;
        $downloadedStylesheet = false;
        $expectedCss = null;
        if ($stylesheetUrl !== '') {
            if (!$this->isAbsoluteHttpUrl($stylesheetUrl)) {
                throw new \RuntimeException(sprintf(
                    'Plugin "%s" v%s: runtime stylesheetUrl must be an absolute https URL to download (got %s).',
                    $pluginId,
                    $version,
                    $stylesheetUrl,
                ));
            }
            $expectedCss = $this->normaliseSha256($expectedChecksums['frontendCss'] ?? null);
            if ($expectedCss === null) {
                throw new \RuntimeException(sprintf(
                    'Plugin "%s" v%s: stylesheetUrl is declared but checksums.frontendCss is missing from the signed payload.',
                    $pluginId,
                    $version,
                ));
            }
            $stylesheetFile = $targetDir . DIRECTORY_SEPARATOR . 'plugin.css';
            $this->downloadVerified($stylesheetUrl, $stylesheetFile, $expectedCss, 'plugin.css');
            $stylesheetWebPath = $this->publicWebPath($pluginId, $version, 'plugin.css');
            $downloadedStylesheet = true;
        }

        $downloadedChunks = $this->downloadChunks(
            entrypointUrl: $entrypointUrl,
            targetDir: $targetDir,
            expectedEntryHash: $expectedEsm,
            expectedCssHash: $expectedCss,
            hasStylesheet: $downloadedStylesheet,
        );

        return [
            'entrypointWebPath' => $this->publicWebPath($pluginId, $version, 'plugin.esm.js'),
            'stylesheetWebPath' => $stylesheetWebPath,
            'downloadedEntrypoint' => true,
            'downloadedStylesheet' => $downloadedStylesheet,
            'downloadedChunks' => $downloadedChunks,
        ];
    }

    /**
     * Fetch the sibling `SHA256SUMS` manifest and download every
     * listed code-split chunk. Returns the list of downloaded chunk
     * filenames. A missing manifest is non-fatal (older releases that
     * predate the chunk-manifest contract simply have no chunks);
     * once the manifest exists every line it declares MUST resolve.
     *
     * @return list<string> chunk filenames written into $targetDir
     */
    private function downloadChunks(
        string $entrypointUrl,
        string $targetDir,
        string $expectedEntryHash,
        ?string $expectedCssHash,
        bool $hasStylesheet,
    ): array {
        $manifestUrl = $this->siblingUrl($entrypointUrl, 'SHA256SUMS');
        $manifestBody = $this->tryFetchManifestBody($manifestUrl);
        if ($manifestBody === null) {
            return [];
        }
        $entries = $this->parseSha256SumsBody($manifestBody);
        if ($entries === []) {
            return [];
        }
        $this->assertManifestAnchored($entries, $expectedEntryHash, $expectedCssHash, $hasStylesheet);

        $downloaded = [];
        foreach ($entries as $fileName => $expectedHash) {
            if ($fileName === 'plugin.esm.js' || $fileName === 'plugin.css') {
                continue;
            }
            if (!$this->isSafeChunkFilename($fileName)) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime SHA256SUMS contains an unsafe file name: %s. Aborting install.',
                    $fileName,
                ));
            }
            $chunkUrl = $this->siblingUrl($entrypointUrl, $fileName);
            $chunkFile = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            $this->downloadVerified($chunkUrl, $chunkFile, $expectedHash, $fileName);
            $downloaded[] = $fileName;
        }

        return $downloaded;
    }

    private function tryFetchManifestBody(string $manifestUrl): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $manifestUrl, [
                'headers' => [
                    'Accept' => 'text/plain, */*',
                    'User-Agent' => 'SelfHelp-Plugin-Manager/1.0',
                ],
                'timeout' => 30,
                'max_duration' => 60,
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime SHA256SUMS fetch failed: %s returned HTTP %d.',
                    $manifestUrl,
                    $status,
                ));
            }
            $body = $response->getContent(false);
            return $body !== '' ? $body : null;
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // HTTP 4xx/5xx already handled by status check above.
            throw $e;
        }
    }

    /**
     * Parse a `<sha256>  <filename>` manifest body. Lines may use one
     * or two spaces (per `sha256sum` conventions) and may include a
     * binary-mode `*` prefix; we accept the canonical form
     * `<64-hex>  <filename>` and tolerate the variations sha256sum
     * itself emits.
     *
     * @return array<string,string> filename → lowercase hex hash
     */
    private function parseSha256SumsBody(string $body): array
    {
        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([0-9a-fA-F]{64})\s+\*?(.+)$/', $line, $m)) {
                continue;
            }
            $hash = strtolower($m[1]);
            $path = trim($m[2]);
            if ($path === '') {
                continue;
            }
            // Tolerate publisher quirks: some manifests carry the
            // .shplugin's archive-root-relative prefix (`artifacts/...`).
            // Strip it so registry installs and archive promotion end
            // up at the same on-disk layout.
            if (str_starts_with($path, 'artifacts/')) {
                $path = substr($path, strlen('artifacts/'));
            }
            // Skip backend/package/ lines: registry artifacts dir only
            // hosts the frontend tree.
            if (str_starts_with($path, 'backend/')) {
                continue;
            }
            if ($path === 'SHA256SUMS' || str_contains($path, '/')) {
                // No nested chunks supported and we never re-download
                // the manifest itself.
                continue;
            }
            $entries[$path] = $hash;
        }

        return $entries;
    }

    /**
     * @param array<string,string> $entries
     */
    private function assertManifestAnchored(
        array $entries,
        string $expectedEntryHash,
        ?string $expectedCssHash,
        bool $hasStylesheet,
    ): void {
        if (!isset($entries['plugin.esm.js'])) {
            throw new \RuntimeException(
                'Plugin runtime SHA256SUMS does not list plugin.esm.js. Refusing to trust its other entries.',
            );
        }
        if (!hash_equals($expectedEntryHash, $entries['plugin.esm.js'])) {
            throw new \RuntimeException(sprintf(
                'Plugin runtime SHA256SUMS lists plugin.esm.js with hash %s but the signed payload pinned %s. Refusing install.',
                $entries['plugin.esm.js'],
                $expectedEntryHash,
            ));
        }
        if ($hasStylesheet && $expectedCssHash !== null && isset($entries['plugin.css'])) {
            if (!hash_equals($expectedCssHash, $entries['plugin.css'])) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime SHA256SUMS lists plugin.css with hash %s but the signed payload pinned %s. Refusing install.',
                    $entries['plugin.css'],
                    $expectedCssHash,
                ));
            }
        }
    }

    private function siblingUrl(string $entrypointUrl, string $fileName): string
    {
        // Resolve a sibling file by replacing the last path segment of
        // the entrypoint URL with the new filename. Preserves origin,
        // path, query is discarded (registry artifacts are static).
        $hashPos = strpos($entrypointUrl, '#');
        $base = $hashPos !== false ? substr($entrypointUrl, 0, $hashPos) : $entrypointUrl;
        $queryPos = strpos($base, '?');
        if ($queryPos !== false) {
            $base = substr($base, 0, $queryPos);
        }
        $lastSlash = strrpos($base, '/');
        if ($lastSlash === false) {
            return $base . '/' . $fileName;
        }
        return substr($base, 0, $lastSlash + 1) . rawurlencode($fileName);
    }

    /**
     * Allow only "plain" code-split chunk filenames: alnum + dash +
     * underscore + dot. Refuse path traversal segments. Vite emits
     * names like `survey-creator-react-DJSXYH6o.js` and
     * `_commonjsHelpers-DaMA6jEr.js`, both of which pass this check.
     */
    private function isSafeChunkFilename(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
    }

    public function publicDir(string $pluginId, string $version): string
    {
        return rtrim($this->projectDir, '/\\')
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'plugin-artifacts'
            . DIRECTORY_SEPARATOR . $pluginId . '-' . $version;
    }

    public function publicWebPath(string $pluginId, string $version, string $file): string
    {
        return '/plugin-artifacts/' . rawurlencode($pluginId . '-' . $version) . '/' . $file;
    }

    private function downloadVerified(string $url, string $targetFile, string $expectedSha256, string $label): void
    {
        $tmpFile = $targetFile . '.tmp.' . bin2hex(random_bytes(4));
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => '*/*',
                    'User-Agent' => 'SelfHelp-Plugin-Manager/1.0',
                ],
                'timeout' => 120,
                'max_duration' => 300,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime fetch failed: %s returned HTTP %d for %s.',
                    $url,
                    $status,
                    $label,
                ));
            }
            $body = $response->getContent(false);
            if ($body === '') {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime fetch returned an empty body for %s (%s).',
                    $label,
                    $url,
                ));
            }
            $actual = strtolower(hash('sha256', $body));
            if (!hash_equals($expectedSha256, $actual)) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime checksum mismatch for %s: expected sha256 %s, downloaded body hashes to %s. Refusing to promote a tampered bundle.',
                    $label,
                    $expectedSha256,
                    $actual,
                ));
            }
            $written = @file_put_contents($tmpFile, $body);
            if ($written === false || $written !== strlen($body)) {
                throw new \RuntimeException(sprintf(
                    'Plugin runtime write failed for %s: could not write %d bytes to %s.',
                    $label,
                    strlen($body),
                    $tmpFile,
                ));
            }
            $this->filesystem->rename($tmpFile, $targetFile, true);
        } catch (\Throwable $e) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            throw $e;
        }
    }

    private function isAbsoluteHttpUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /**
     * Accepts sha256-<hex>, sha256:<hex>, or raw <hex>. Returns the
     * 64-char lowercase hex digest, or null when the input is missing
     * / malformed.
     */
    private function normaliseSha256(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $stripped = $value;
        if (str_starts_with($stripped, 'sha256-') || str_starts_with($stripped, 'sha256:')) {
            $stripped = substr($stripped, 7);
        }
        $stripped = strtolower($stripped);
        if (!preg_match('/^[0-9a-f]{64}$/', $stripped)) {
            return null;
        }

        return $stripped;
    }
}
