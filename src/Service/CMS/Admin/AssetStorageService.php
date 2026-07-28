<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

/**
 * Single source of truth for where asset bytes live on disk and how they are
 * addressed over HTTP.
 *
 * Assets are stored OUTSIDE the document root (`var/assets/`, not
 * `public/uploads/assets/`) so the web server cannot serve them directly. In
 * `public/` the Apache rule `RewriteCond %{REQUEST_FILENAME} !-f` short-circuits
 * to the file before PHP runs, which makes folder ACLs unenforceable. Delivery
 * therefore goes through {@see \App\Controller\Api\V1\AssetController}, which
 * applies {@see AssetFolderAclService} on every request.
 *
 * `assets.file_path` keeps its historical `uploads/assets/<folder>/<name>`
 * shape: it is a logical key, not a filesystem or URL path. Both the on-disk
 * location and the public URL are derived from it here, so stored content does
 * not need rewriting.
 */
class AssetStorageService
{
    /**
     * Logical prefix persisted in `assets.file_path`. Retained for continuity
     * with existing rows and bundle manifests; it is NOT a document-root path.
     */
    public const LOGICAL_PREFIX = 'uploads/assets/';

    /** Storage root relative to the project dir, outside `public/`. */
    private const STORAGE_ROOT = '/var/assets/';

    /** URL prefix served by the ACL-enforcing delivery route. */
    public const URL_PREFIX = '/cms-api/v1/assets/';

    public function __construct(
        private readonly string $projectDir
    ) {
    }

    /**
     * Absolute path to the storage root (no trailing slash).
     */
    public function storageRoot(): string
    {
        return rtrim($this->projectDir . self::STORAGE_ROOT, '/\\');
    }

    /**
     * Absolute directory for one folder's bytes.
     */
    public function folderPath(string $folder): string
    {
        return $this->storageRoot() . '/' . $folder;
    }

    /**
     * Resolve a stored `file_path` to its absolute location on disk.
     */
    public function absolutePath(string $filePath): string
    {
        $relative = str_starts_with($filePath, self::LOGICAL_PREFIX)
            ? substr($filePath, strlen(self::LOGICAL_PREFIX))
            : $filePath;

        return $this->storageRoot() . '/' . $relative;
    }

    /**
     * The ACL-enforced delivery URL for a stored `file_path`.
     */
    public function publicUrl(string $filePath): string
    {
        $relative = str_starts_with($filePath, self::LOGICAL_PREFIX)
            ? substr($filePath, strlen(self::LOGICAL_PREFIX))
            : $filePath;

        return self::URL_PREFIX . $relative;
    }

    /**
     * Resolve `<folder>/<filename>` from a delivery URL to an absolute path,
     * refusing anything that escapes the folder (`..`, absolute paths, symlink
     * traversal). Returns null when the request is unsafe or the file is
     * missing — the caller must not distinguish the two to avoid disclosing
     * which paths exist.
     */
    public function resolveForDelivery(string $folder, string $filename): ?string
    {
        // Reject separators/traversal before touching the filesystem.
        if ($folder === '' || $filename === ''
            || str_contains($folder, '/') || str_contains($folder, '\\')
            || str_contains($filename, '/') || str_contains($filename, '\\')
            || str_contains($folder, '..') || str_contains($filename, '..')
        ) {
            return null;
        }

        $candidate = $this->folderPath($folder) . '/' . $filename;

        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // Defence in depth: the resolved path must stay inside the folder even
        // if a symlink pointed elsewhere.
        $folderReal = realpath($this->folderPath($folder));
        if ($folderReal === false || !str_starts_with($real, $folderReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
