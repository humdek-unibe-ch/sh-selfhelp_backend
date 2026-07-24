<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Repository\AssetRepository;
use App\Service\Core\BaseService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export/import assets as a ZIP bundle (physical files + manifest.json),
 * mirroring the pages/navigation bundle pattern.
 *
 * The bundle layout is:
 *   manifest.json          – bundle metadata + one entry per asset
 *   files/<folder>/<name>  – the physical asset bytes
 *
 * Import re-uses {@see AdminAssetService::createAsset} so file-type validation,
 * transactions, cache invalidation, and folder-level manage ACL enforcement all
 * apply identically to a normal upload.
 */
class AssetExportImportService extends BaseService
{
    private const BUNDLE_TYPE = 'selfhelp/asset-bundle';
    private const BUNDLE_VERSION = '1.0';
    private const MANIFEST_NAME = 'manifest.json';
    private const FILES_PREFIX = 'files/';

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AdminAssetService $adminAssetService,
        private readonly AssetFolderAclService $folderAclService,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Build a ZIP bundle for the given folders (or every readable folder when
     * `$folders` is empty). Returns the absolute path to a temporary ZIP file
     * the caller is responsible for streaming and deleting.
     *
     * @param list<string> $folders
     */
    public function exportToZipFile(array $folders): string
    {
        // Closed-by-default allow-list: null = admin (no restriction).
        $visibleFolders = $this->folderAclService->getVisibleFoldersOrNull();

        $assets = $this->assetRepository->findForExport($folders, $visibleFolders);
        if ($assets === []) {
            $this->throwNotFound('No assets found for export');
        }

        $zip = new \ZipArchive();
        $zipPath = $this->createTempFilePath('asset-export-', '.zip');

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->throwServerError('Unable to create export archive');
        }

        $manifestEntries = [];
        foreach ($assets as $asset) {
            $sourcePath = $this->projectDir . '/public/' . $asset->getFilePath();
            if (!is_file($sourcePath)) {
                // Skip DB rows whose physical file is missing rather than failing
                // the whole export; the manifest only lists files actually bundled.
                continue;
            }

            $folder = $asset->getFolder() ?? '';
            $fileName = (string) $asset->getFileName();
            $entryPath = self::FILES_PREFIX . ($folder !== '' ? $folder . '/' : '') . $fileName;

            $zip->addFile($sourcePath, $entryPath);

            $manifestEntries[] = [
                'folder' => $asset->getFolder(),
                'file_name' => $fileName,
                'asset_type' => $asset->getAssetType()->getLookupValue(),
                'bundle_path' => $entryPath,
            ];
        }

        if ($manifestEntries === []) {
            $zip->close();
            @unlink($zipPath);
            $this->throwNotFound('No asset files available for export');
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

        $zip->close();

        return $zipPath;
    }

    /**
     * Import an uploaded asset bundle ZIP. Each manifest entry is created via
     * the normal asset-create path (so ACL + validation apply per folder).
     *
     * @return array{imported: int, skipped: int, errors: list<array{file: string, error: string}>}
     */
    public function importFromZip(UploadedFile $file, bool $overwrite): array
    {
        if (!$file->isValid()) {
            $this->throwBadRequest('File upload failed: ' . $file->getErrorMessage());
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getPathname()) !== true) {
            $this->throwBadRequest('Uploaded file is not a valid ZIP archive');
        }

        try {
            $manifest = $this->readManifest($zip);
            $extractDir = $this->extractToTempDir($zip);
        } finally {
            $zip->close();
        }

        try {
            return $this->importManifestEntries($manifest, $extractDir, $overwrite);
        } finally {
            $this->removeDir($extractDir);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{imported: int, skipped: int, errors: list<array{file: string, error: string}>}
     */
    private function importManifestEntries(array $manifest, string $extractDir, bool $overwrite): array
    {
        $entries = $this->asArray($manifest['assets'] ?? null);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($entries as $rawEntry) {
            $entry = $this->asAssocArray($rawEntry);
            $bundlePath = $this->asString($entry['bundle_path'] ?? '');
            $fileName = $this->asString($entry['file_name'] ?? '');
            $folder = $this->asStringOrNull($entry['folder'] ?? null);

            $sourcePath = $extractDir . '/' . $bundlePath;

            // Guard against path traversal in the manifest.
            if ($bundlePath === '' || !str_starts_with($bundlePath, self::FILES_PREFIX) || !$this->isInsideDir($extractDir, $sourcePath)) {
                $errors[] = ['file' => $fileName !== '' ? $fileName : $bundlePath, 'error' => 'Invalid bundle path'];
                continue;
            }

            if (!is_file($sourcePath)) {
                $errors[] = ['file' => $fileName !== '' ? $fileName : $bundlePath, 'error' => 'File missing from bundle'];
                continue;
            }

            try {
                // Wrap the extracted file as an UploadedFile in test-mode (4th arg
                // true) so ::createAsset moves it without an is_uploaded_file check.
                $uploaded = new UploadedFile($sourcePath, $fileName !== '' ? $fileName : basename($sourcePath), null, null, true);

                $this->adminAssetService->createAsset(
                    $uploaded,
                    ['folder' => $folder, 'file_name' => $fileName],
                    $overwrite
                );
                $imported++;
            } catch (\App\Exception\ServiceException $e) {
                if ($e->getCode() === Response::HTTP_CONFLICT) {
                    $skipped++;
                    continue;
                }
                $errors[] = ['file' => $fileName !== '' ? $fileName : $bundlePath, 'error' => $e->getMessage()];
            } catch (\Exception $e) {
                $errors[] = ['file' => $fileName !== '' ? $fileName : $bundlePath, 'error' => $e->getMessage()];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName(self::MANIFEST_NAME);
        if ($raw === false) {
            $this->throwBadRequest('Bundle is missing ' . self::MANIFEST_NAME);
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

        return $manifest;
    }

    private function extractToTempDir(\ZipArchive $zip): string
    {
        $dir = $this->createTempFilePath('asset-import-', '');
        if (is_file($dir)) {
            @unlink($dir);
        }
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->throwServerError('Unable to create import work directory');
        }

        if (!$zip->extractTo($dir)) {
            $this->removeDir($dir);
            $this->throwBadRequest('Unable to extract bundle');
        }

        return $dir;
    }

    private function createTempFilePath(string $prefix, string $suffix): string
    {
        return sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8)) . $suffix;
    }

    private function isInsideDir(string $dir, string $candidate): bool
    {
        $realDir = realpath($dir);
        $realCandidate = realpath($candidate);
        if ($realDir === false || $realCandidate === false) {
            return false;
        }

        return str_starts_with($realCandidate, $realDir . DIRECTORY_SEPARATOR);
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
