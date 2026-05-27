<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Archive;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Validates and extracts a `.shplugin` upload into a staging dir under
 * `var/plugins/<id>-<version>/staging/`.
 *
 * Hardening:
 *
 *   - Refuses non-`.shplugin` extensions and oversized files.
 *   - Confirms ZIP magic-bytes ("PK\x03\x04") before opening.
 *   - Enforces zip-slip protection: absolute paths and `..` segments
 *     in zip entries are rejected.
 *   - Pre-validates the presence of every required archive member
 *     (`plugin.json`, `signature.json`, `artifacts/SHA256SUMS`,
 *     `artifacts/plugin.esm.js`). `artifacts/plugin.css` is OPTIONAL —
 *     plugins without a stylesheet (admin-only UI, headless services)
 *     don't ship one and `sign.mjs` omits the corresponding fields
 *     from the canonical payload.
 *   - When the manifest declares `archive.mode = "standalone"`,
 *     additionally requires `backend/package/composer.json` so
 *     downstream validation can read the staged Composer package.
 *     Connected archives (`archive.mode = "connected"`) only need
 *     the base required-files list.
 *
 * The extractor does not parse the manifest beyond `id`, `version`,
 * and `archive.mode`, and never verifies signatures — that lives in
 * `PluginArchiveValidator`. It returns the staging directory path
 * for downstream callers.
 */
final class PluginArchiveExtractor
{
    // `artifacts/plugin.css` is intentionally OPTIONAL. Plenty of
    // plugins (admin-only UI extensions, headless service plugins,
    // anything whose Vite build does not emit a CSS file) ship without
    // a stylesheet. The canonical payload mirrors that — `sign.mjs`
    // omits `stylesheetUrl` + `frontendCss` when there is no CSS file
    // to hash. The host's `PluginArchiveValidator` consults the actual
    // staging dir for CSS presence and recomputes the canonical
    // payload identically either way.
    public const REQUIRED_FILES = [
        'plugin.json',
        'signature.json',
        'artifacts/SHA256SUMS',
        'artifacts/plugin.esm.js',
    ];

    // Additional files required when the manifest declares
    // `archive.mode = "standalone"`. `composer.json` is the anchor:
    // every other file under `backend/package/` is described by
    // `composer.json#autoload` and hashed via SHA256SUMS. If
    // `composer.json` is missing the validator can't even tell what
    // the package name is, so we hard-require it at extraction time
    // to fail fast.
    public const STANDALONE_REQUIRED_FILES = [
        'backend/package/composer.json',
    ];

    // Top-level archive prefixes that the extractor + validator
    // recognise. Anything else is rejected at extraction time to
    // keep the surface area small (every signed artifact lives
    // under one of these prefixes).
    private const ALLOWED_TOP_LEVEL_PREFIXES = [
        'artifacts/',
        'backend/',
    ];

    // Top-level files (not directories) the extractor accepts.
    private const ALLOWED_TOP_LEVEL_FILES = [
        'plugin.json',
        'signature.json',
        'README.md',
        'LICENSE',
        'CHANGELOG.md',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly int $maxBytes = 20971520,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array{stagingDir:string,pluginId:string,version:string}
     */
    public function extract(UploadedFile $file): array
    {
        $this->assertUploadValid($file);
        $tmpPath = $file->getRealPath();
        if ($tmpPath === false || !is_file($tmpPath)) {
            throw new PluginArchiveException('Uploaded archive is not readable.');
        }
        $this->assertZipMagic($tmpPath);

        $zip = new \ZipArchive();
        $opened = $zip->open($tmpPath, \ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new PluginArchiveException(sprintf('Archive is not a valid ZIP (ZipArchive code %s).', (string) $opened));
        }

        try {
            $this->assertRequiredEntries($zip, self::REQUIRED_FILES);

            $manifestRaw = $zip->getFromName('plugin.json');
            if ($manifestRaw === false) {
                throw new PluginArchiveException('plugin.json missing from archive.');
            }
            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                throw new PluginArchiveException('plugin.json in archive is not a JSON object.');
            }
            $pluginId = $this->requireManifestString($manifest, 'id');
            $version = $this->requireManifestString($manifest, 'version');

            $archiveMode = $this->readArchiveMode($manifest);
            if ($archiveMode === 'standalone') {
                $this->assertRequiredEntries($zip, self::STANDALONE_REQUIRED_FILES);
            }

            $stagingDir = $this->stagingDir($pluginId, $version);
            if ($this->filesystem->exists($stagingDir)) {
                $this->filesystem->remove($stagingDir);
            }
            $this->filesystem->mkdir($stagingDir, 0775);

            // Extract every entry, guarding against zip-slip and rejecting
            // any path that does not live under one of the recognised
            // top-level prefixes (artifacts/, backend/) or the allow-listed
            // top-level files (plugin.json, signature.json, README, …).
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $name === '') {
                    continue;
                }
                $this->assertSafeEntryName($name);
                if (str_ends_with($name, '/')) {
                    $this->filesystem->mkdir($stagingDir . '/' . $name, 0775);
                    continue;
                }
                $target = $stagingDir . '/' . $name;
                $this->filesystem->mkdir(dirname($target), 0775);
                if (!copy('zip://' . $tmpPath . '#' . $name, $target)) {
                    throw new PluginArchiveException(sprintf('Failed to extract "%s" from archive.', $name));
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'stagingDir' => $stagingDir,
            'pluginId' => $pluginId,
            'version' => $version,
        ];
    }

    public function stagingDir(string $pluginId, string $version): string
    {
        return rtrim($this->projectDir, '/\\')
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . $pluginId . '-' . $version
            . DIRECTORY_SEPARATOR . 'staging';
    }

    private function assertUploadValid(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();
        if (!str_ends_with(strtolower($name), '.shplugin')) {
            throw new PluginArchiveException(sprintf(
                'Uploaded file "%s" does not have a .shplugin extension.',
                $name,
            ));
        }
        $size = $file->getSize();
        if ($size === false || $size === null) {
            throw new PluginArchiveException('Uploaded archive size is unknown.');
        }
        if ($size <= 0) {
            throw new PluginArchiveException('Uploaded archive is empty.');
        }
        if ($size > $this->maxBytes) {
            throw new PluginArchiveException(sprintf(
                'Uploaded archive is %d bytes; the maximum allowed is %d. Override with SELFHELP_PLUGIN_ARCHIVE_MAX_BYTES.',
                $size,
                $this->maxBytes,
            ));
        }
    }

    private function assertZipMagic(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new PluginArchiveException('Cannot open uploaded archive for ZIP magic-bytes check.');
        }
        try {
            $magic = fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if ($magic === false || strlen($magic) !== 4 || substr($magic, 0, 2) !== 'PK') {
            throw new PluginArchiveException('Uploaded archive does not begin with the ZIP magic bytes (PK).');
        }
    }

    /**
     * @param list<string> $required
     */
    private function assertRequiredEntries(\ZipArchive $zip, array $required): void
    {
        $missing = [];
        foreach ($required as $entry) {
            if ($zip->locateName($entry) === false) {
                $missing[] = $entry;
            }
        }
        if ($missing !== []) {
            throw new PluginArchiveException(sprintf(
                'Archive is missing required entries: %s',
                implode(', ', $missing),
            ));
        }
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new PluginArchiveException(sprintf('Archive entry "%s" uses an absolute path (zip-slip).', $name));
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            throw new PluginArchiveException(sprintf('Archive entry "%s" contains ".." segments (zip-slip).', $name));
        }
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            throw new PluginArchiveException(sprintf('Archive entry "%s" uses a Windows drive prefix.', $name));
        }
        // Restrict to the allow-listed top-level layout. Directory
        // entries (paths ending with `/`) must start with an allowed
        // prefix; file entries may either start with an allowed prefix
        // OR be one of the explicitly allow-listed top-level files.
        $isDirectory = str_ends_with($name, '/');
        foreach (self::ALLOWED_TOP_LEVEL_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return;
            }
        }
        if (!$isDirectory && in_array($name, self::ALLOWED_TOP_LEVEL_FILES, true)) {
            return;
        }
        throw new PluginArchiveException(sprintf(
            'Archive entry "%s" is outside the supported .shplugin layout. '
            . 'Files must live under artifacts/ or backend/ (or be one of: %s).',
            $name,
            implode(', ', self::ALLOWED_TOP_LEVEL_FILES),
        ));
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function requireManifestString(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PluginArchiveException(sprintf('plugin.json in archive is missing "%s".', $key));
        }
        return $value;
    }

    /**
     * Reads `archive.mode` from the manifest, defaulting to
     * `connected`. The schema enforces the enum; here we only need to
     * tolerate a manifest without the optional `archive` block.
     *
     * @param array<string,mixed> $manifest
     */
    private function readArchiveMode(array $manifest): string
    {
        $archive = $manifest['archive'] ?? null;
        if (!is_array($archive)) {
            return 'connected';
        }
        $mode = $archive['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            return 'connected';
        }
        if ($mode !== 'connected' && $mode !== 'standalone') {
            throw new PluginArchiveException(sprintf(
                'plugin.json in archive has unsupported archive.mode "%s". Expected "connected" or "standalone".',
                $mode,
            ));
        }
        return $mode;
    }
}
