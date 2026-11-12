<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use App\Entity\Group;
use App\Repository\AssetRepository;
use App\Service\Core\BaseService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export/import assets as a ZIP bundle (physical files + manifest.json),
 * mirroring the pages/navigation bundle pattern.
 *
 * Bundle v2.0 layout:
 *   manifest.json   – bundle metadata + one entry per asset (uuid, display
 *                     name, alt, mime type, extension, group names, folder
 *                     path, `bundle_path`)
 *   files/<name>-<uuid>.<ext> – the physical asset bytes, named like a
 *                     download; the manifest's `bundle_path` names each one
 *
 * An entry's `folder_path` is the list of folder names from the top level down
 * (`["Courses", "Week 1"]`; empty or absent = top level). It is optional, so
 * bundles made before it existed still import (into the top level).
 *
 * Import never overwrites: an asset whose uuid already exists is skipped, so a
 * bundle can be re-imported safely. An asset that names a group missing on this
 * instance is not imported and is reported in the errors. Folders are created
 * by name where they do not exist. Only v2.0 bundles are accepted. Import
 * re-uses {@see AdminAssetService::createAsset} so file-type validation,
 * transactions and cache invalidation apply identically to a normal upload.
 *
 * An uploaded bundle is untrusted. Import reads the archive entry by entry
 * and never extracts it as a whole, so files the manifest does not name are
 * never written; it counts the bytes it really reads rather than trusting the
 * sizes the archive declares; and it stops at the limits below. Export obeys
 * the same asset count and total size limits, so an export can always be
 * imported (the limits are constructor arguments only so tests can lower them).
 */
class AssetExportImportService extends BaseService
{
    private const BUNDLE_TYPE = 'selfhelp/asset-bundle';
    private const BUNDLE_VERSION = '2.0';
    private const MANIFEST_NAME = 'manifest.json';
    private const FILES_PREFIX = 'files/';

    /** Most assets in one bundle (export and import). */
    public const MAX_ASSETS = 2000;
    /** Largest manifest.json read from a bundle, in bytes. */
    public const MAX_MANIFEST_BYTES = 8 * 1024 * 1024;
    /** Largest single file read from a bundle, in bytes. */
    public const MAX_FILE_BYTES = 512 * 1024 * 1024;
    /** Most file bytes in one bundle, in total (export and import). */
    public const MAX_TOTAL_BYTES = 4 * 1024 * 1024 * 1024;
    /** Deepest folder path an entry may carry: the deepest a folder may sit. */
    public const MAX_FOLDER_DEPTH = AdminAssetService::MAX_FOLDER_DEPTH;

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AdminAssetService $adminAssetService,
        private readonly AssetAccessService $accessService,
        private readonly AssetStorageService $storage,
        private readonly int $maxAssets = self::MAX_ASSETS,
        private readonly int $maxFileBytes = self::MAX_FILE_BYTES,
        private readonly int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {
    }

    /**
     * Build a ZIP bundle for the given assets (or every readable asset when
     * `$assetIds` is empty). Returns the absolute path to a temporary ZIP file
     * the caller is responsible for streaming and deleting.
     *
     * @param list<int> $assetIds
     */
    public function exportToZipFile(array $assetIds): string
    {
        $assets = $this->assetRepository->findForExport($assetIds, $this->accessService->manageableAssetIdsOrNull());
        if ($assets === []) {
            $this->throwNotFound('No assets found for export');
        }
        if (count($assets) > $this->maxAssets) {
            $this->throwBadRequest(sprintf(
                'A bundle holds at most %d assets (%d selected): export fewer assets at a time',
                $this->maxAssets,
                count($assets)
            ));
        }

        // Which files go in, and how many bytes they are, is known before the
        // archive is started: a selection too big to import again is refused
        // here, not after minutes of compressing it to a temporary file.
        $sources = [];
        $totalBytes = 0;
        foreach ($assets as $asset) {
            $sourcePath = $this->storage->resolveForDelivery($asset->getUuid());
            if ($sourcePath === null) {
                // Skip DB rows whose physical file is missing rather than failing
                // the whole export; the manifest only lists files actually bundled.
                continue;
            }

            $totalBytes += (int) filesize($sourcePath);
            if ($totalBytes > $this->maxTotalBytes) {
                $this->throwBadRequest(sprintf(
                    'A bundle holds at most %s of files: export fewer assets at a time',
                    $this->humanBytes($this->maxTotalBytes)
                ));
            }
            $sources[] = [$asset, $sourcePath];
        }
        if ($sources === []) {
            $this->throwNotFound('No asset files available for export');
        }

        $zip = new \ZipArchive();
        $zipPath = $this->createTempFilePath('asset-export-', '.zip');

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->throwServerError('Unable to create export archive');
        }

        $manifestEntries = [];
        foreach ($sources as [$asset, $sourcePath]) {
            // The same name a download gets, `<name>-<uuid>.<extension>`, so an
            // unpacked bundle opens in the right program. The manifest names
            // it in `bundle_path`; the importer reads by that, never by guess.
            $entryPath = self::FILES_PREFIX . $this->storage->downloadName($asset);
            $zip->addFile($sourcePath, $entryPath);

            $manifestEntries[] = [
                'uuid' => $asset->getUuid(),
                'name' => $asset->getName(),
                'alt' => $asset->getAlt(),
                'mime_type' => $asset->getMimeType(),
                'extension' => $asset->getExtension() ?? AssetFileTypes::extensionForMimeType($asset->getMimeType()),
                'asset_type' => $asset->getAssetType()->getLookupValue(),
                // Group and folder NAMES travel, not ids: ids differ between instances.
                'groups' => array_values(array_filter(array_map(
                    static fn (Group $group): ?string => $group->getName(),
                    $asset->getGroups()->toArray()
                ), 'is_string')),
                'folder_path' => $this->folderPath($asset),
                'bundle_path' => $entryPath,
            ];
        }

        $manifest = [
            'bundle_type' => self::BUNDLE_TYPE,
            'bundle_version' => self::BUNDLE_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'assets' => $manifestEntries,
        ];

        $zip->addFromString(
            self::MANIFEST_NAME,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        // The files are read when the archive is closed: one that vanished or
        // cannot be read since the check above makes the close fail.
        if (!$zip->close()) {
            @unlink($zipPath);
            $this->throwServerError('Unable to create export archive');
        }

        return $zipPath;
    }

    /**
     * Import an uploaded asset bundle ZIP. Each manifest entry is created via
     * the normal asset-create path.
     *
     * @return array{imported: int, skipped: int, errors: list<array{file: string, error: string}>}
     */
    public function importFromZip(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            $this->throwBadRequest('File upload failed: ' . $file->getErrorMessage());
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getPathname(), \ZipArchive::RDONLY) !== true) {
            $this->throwBadRequest('Uploaded file is not a valid ZIP archive');
        }

        $workDir = null;
        try {
            // The manifest and one file per asset: more entries than that is
            // not a bundle this system made, whatever the manifest says.
            if ($zip->numFiles > $this->maxAssets + 1) {
                $this->throwBadRequest(sprintf('A bundle holds at most %d assets', $this->maxAssets));
            }

            $manifest = $this->readManifest($zip);
            $entries = $this->asArray($manifest['assets'] ?? null);
            if (count($entries) > $this->maxAssets) {
                $this->throwBadRequest(sprintf('A bundle holds at most %d assets', $this->maxAssets));
            }

            $workDir = $this->createWorkDir();

            return $this->importManifestEntries($zip, $entries, $workDir);
        } finally {
            $zip->close();
            if ($workDir !== null) {
                $this->removeDir($workDir);
            }
        }
    }

    /**
     * @param array<mixed> $entries the manifest's asset entries
     * @return array{imported: int, skipped: int, errors: list<array{file: string, error: string}>}
     */
    private function importManifestEntries(\ZipArchive $zip, array $entries, string $workDir): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $totalBytes = 0;
        $position = 0;

        foreach ($entries as $rawEntry) {
            $position++;
            $entry = $this->asAssocArray($rawEntry);
            $bundlePath = $this->asString($entry['bundle_path'] ?? '');
            $uuid = $this->asString($entry['uuid'] ?? '');
            // Stored files have no extension; the entry's `extension` (else
            // the one of its mime type, for bundles made before it existed)
            // gives the client name the extension createAsset validates.
            $fileName = $uuid . self::extensionSuffix($entry);
            $label = $uuid !== '' ? $uuid : $bundlePath;

            // The path is only ever used as the name of an entry to read, but
            // a manifest that points anywhere else than files/<name> is wrong.
            if (preg_match('#^' . preg_quote(self::FILES_PREFIX, '#') . '[^/\\\\\x00]+$#', $bundlePath) !== 1) {
                $errors[] = ['file' => $label, 'error' => 'Invalid bundle path'];
                continue;
            }

            $stat = $zip->statName($bundlePath);
            if ($stat === false) {
                $errors[] = ['file' => $label, 'error' => 'File missing from bundle'];
                continue;
            }

            if (!$this->storage->isUuid($uuid)) {
                $errors[] = ['file' => $label, 'error' => 'Invalid asset uuid'];
                continue;
            }

            if ($this->assetRepository->findOneByUuid($uuid) !== null) {
                $skipped++;
                continue;
            }

            $data = ['uuid' => $uuid, 'name' => $entry['name'] ?? null, 'alt' => $entry['alt'] ?? null];
            if (array_key_exists('groups', $entry)) {
                $names = array_values(array_filter($this->asArray($entry['groups']), 'is_string'));
                $groups = $this->accessService->groupsByName($names);

                // An asset naming a group this instance does not have is NOT
                // imported: dropping the group would silently publish a
                // restricted asset (no groups = public). Same rule as the users
                // CSV import, which errors the row for an unknown group name.
                $foundNames = array_map(static fn (Group $group): string => mb_strtolower((string) $group->getName()), $groups);
                $missing = array_values(array_unique(array_filter(
                    $names,
                    static fn (string $name): bool => !in_array(mb_strtolower($name), $foundNames, true)
                )));
                if ($missing !== []) {
                    $errors[] = ['file' => $label, 'error' => 'Unknown group name(s): ' . implode(', ', $missing)];
                    continue;
                }

                $data['group_ids'] = array_map(
                    static fn (Group $group): int => (int) $group->getId(),
                    $groups
                );
            }

            $folderPath = $this->folderPathOf($entry);
            if ($folderPath === null) {
                $errors[] = ['file' => $label, 'error' => 'Invalid folder path'];
                continue;
            }

            if ($stat['size'] > $this->maxFileBytes) {
                $errors[] = ['file' => $label, 'error' => $this->fileTooLarge()];
                continue;
            }
            if ($totalBytes + $stat['size'] > $this->maxTotalBytes) {
                // Nothing after this point would fit either: stop here.
                $errors[] = ['file' => $label, 'error' => sprintf('Bundle exceeds the %s it may hold in total; import stopped here', $this->humanBytes($this->maxTotalBytes))];
                break;
            }

            $sourcePath = null;
            try {
                $sourcePath = $this->extractEntry($zip, $bundlePath, $workDir . '/' . $position);
                $totalBytes += (int) filesize($sourcePath);

                if ($folderPath !== []) {
                    $data['folder_id'] = $this->adminAssetService->ensureFolderPath($folderPath)?->getId();
                }

                // Wrap the extracted file as an UploadedFile in test-mode (4th arg
                // true) so ::createAsset moves it without an is_uploaded_file
                // check. The client name carries the extension for validation.
                $uploaded = new UploadedFile($sourcePath, $fileName, null, null, true);

                $this->adminAssetService->createAsset($uploaded, $data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['file' => $label, 'error' => $e->getMessage()];
            } finally {
                if ($sourcePath !== null && is_file($sourcePath)) {
                    @unlink($sourcePath);
                }
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * `.<extension>` for a manifest entry: its `extension` when it is a plain
     * extension, else the one of its `mime_type`, else nothing (createAsset
     * then refuses the file as an unknown type).
     *
     * @param array<string, mixed> $entry
     */
    private static function extensionSuffix(array $entry): string
    {
        $extension = $entry['extension'] ?? null;
        if (is_string($extension) && preg_match('/^[A-Za-z0-9]{1,20}$/', $extension) === 1) {
            return '.' . $extension;
        }

        $mimeType = $entry['mime_type'] ?? null;
        $fromMime = AssetFileTypes::extensionForMimeType(is_string($mimeType) ? $mimeType : null);

        return $fromMime !== null ? '.' . $fromMime : '';
    }

    /**
     * Copy ONE entry of the archive to `$targetPath`, reading at most
     * the largest file a bundle may hold: the sizes an archive declares can be
     * false, so the bytes are counted as they are read.
     *
     * @return string the path written
     */
    private function extractEntry(\ZipArchive $zip, string $entryName, string $targetPath): string
    {
        $source = $zip->getStream($entryName);
        $target = fopen($targetPath, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            $this->throwBadRequest('Unable to read the file from the bundle');
        }

        $copied = stream_copy_to_stream($source, $target, $this->maxFileBytes + 1);
        fclose($source);
        fclose($target);

        if ($copied === false || $copied > $this->maxFileBytes) {
            @unlink($targetPath);
            $this->throwBadRequest($this->fileTooLarge());
        }

        return $targetPath;
    }

    private function fileTooLarge(): string
    {
        return sprintf('File is larger than the %s a bundle file may have', $this->humanBytes($this->maxFileBytes));
    }

    /**
     * A size for a message: whole GB, MB or KB when it is exactly that, bytes otherwise.
     */
    private function humanBytes(int $bytes): string
    {
        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $size]) {
            if ($bytes >= $size && $bytes % $size === 0) {
                return intdiv($bytes, $size) . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }

    /**
     * The folder path an entry carries: `[]` for the top level (absent or
     * empty), the names from the top down, or null when it is not a list of
     * 1 to {@see MAX_FOLDER_DEPTH} non-empty names.
     *
     * @param array<string, mixed> $entry
     * @return list<string>|null
     */
    private function folderPathOf(array $entry): ?array
    {
        if (!array_key_exists('folder_path', $entry) || $entry['folder_path'] === null) {
            return [];
        }
        if (!is_array($entry['folder_path']) || !array_is_list($entry['folder_path']) || count($entry['folder_path']) > self::MAX_FOLDER_DEPTH) {
            return null;
        }

        $names = [];
        foreach ($entry['folder_path'] as $name) {
            if (!is_string($name) || trim($name) === '') {
                return null;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The names of the asset's folders from the top level down.
     *
     * @return list<string>
     */
    private function folderPath(Asset $asset): array
    {
        $names = [];
        $seen = [];
        for ($folder = $asset->getFolder(); $folder !== null && !isset($seen[spl_object_id($folder)]); $folder = $folder->getParent()) {
            $seen[spl_object_id($folder)] = true;
            array_unshift($names, $folder->getName());
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(\ZipArchive $zip): array
    {
        $stat = $zip->statName(self::MANIFEST_NAME);
        if ($stat === false) {
            $this->throwBadRequest('Bundle is missing ' . self::MANIFEST_NAME);
        }
        if ($stat['size'] > self::MAX_MANIFEST_BYTES) {
            $this->throwBadRequest('Bundle ' . self::MANIFEST_NAME . ' is too large');
        }

        $stream = $zip->getStream(self::MANIFEST_NAME);
        if ($stream === false) {
            $this->throwBadRequest('Bundle is missing ' . self::MANIFEST_NAME);
        }
        $raw = stream_get_contents($stream, self::MAX_MANIFEST_BYTES + 1);
        fclose($stream);
        if ($raw === false || strlen($raw) > self::MAX_MANIFEST_BYTES) {
            $this->throwBadRequest('Bundle ' . self::MANIFEST_NAME . ' is too large');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->throwBadRequest('Bundle ' . self::MANIFEST_NAME . ' is not valid JSON');
        }

        $manifest = $this->asAssocArray($decoded);
        if (($manifest['bundle_type'] ?? null) !== self::BUNDLE_TYPE) {
            $this->throwBadRequest('Unsupported bundle type');
        }
        if (($manifest['bundle_version'] ?? null) !== self::BUNDLE_VERSION) {
            $this->throwBadRequest('Unsupported bundle version (expected ' . self::BUNDLE_VERSION . ')');
        }

        return $manifest;
    }

    /**
     * A private directory for the one file being imported at a time.
     */
    private function createWorkDir(): string
    {
        $dir = $this->createTempFilePath('asset-import-', '');
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->throwServerError('Unable to create import work directory');
        }

        return $dir;
    }

    private function createTempFilePath(string $prefix, string $suffix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8)) . $suffix;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function throwServerError(string $message): never
    {
        throw new \App\Exception\ServiceException($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
