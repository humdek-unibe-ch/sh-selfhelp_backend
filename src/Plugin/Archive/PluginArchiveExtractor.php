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
 *     `artifacts/plugin.esm.js`, `artifacts/plugin.css`).
 *
 * The extractor does not parse the manifest or verify signatures —
 * that lives in `PluginArchiveValidator`. It returns the staging
 * directory path for downstream callers.
 */
final class PluginArchiveExtractor
{
    public const REQUIRED_FILES = [
        'plugin.json',
        'signature.json',
        'artifacts/SHA256SUMS',
        'artifacts/plugin.esm.js',
        'artifacts/plugin.css',
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
            $this->assertRequiredEntries($zip);

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

            $stagingDir = $this->stagingDir($pluginId, $version);
            if ($this->filesystem->exists($stagingDir)) {
                $this->filesystem->remove($stagingDir);
            }
            $this->filesystem->mkdir($stagingDir, 0775);

            // Extract every entry, guarding against zip-slip.
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

    private function assertRequiredEntries(\ZipArchive $zip): void
    {
        $missing = [];
        foreach (self::REQUIRED_FILES as $required) {
            if ($zip->locateName($required) === false) {
                $missing[] = $required;
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
}
