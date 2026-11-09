<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use Symfony\Component\Mime\MimeTypes;

/**
 * Single source of truth for where asset bytes live on disk and how they are
 * addressed over HTTP.
 *
 * Assets are stored OUTSIDE the document root (`var/assets/`, not
 * `public/uploads/assets/`) so the web server cannot serve them directly. In
 * `public/` the Apache rule `RewriteCond %{REQUEST_FILENAME} !-f` short-circuits
 * to the file before PHP runs, which makes access checks unenforceable.
 * Delivery therefore goes through {@see \App\Controller\Api\V1\AssetController},
 * which applies {@see AssetAccessService} on every request.
 *
 * `assets.file_path` is a logical key, `uploads/assets/<uuid>`; the on-disk
 * location is derived from it here. Files uploaded before folders were removed
 * keep `uploads/assets/<folder>/<name>`.
 */
class AssetStorageService
{
    /**
     * Logical prefix persisted in `assets.file_path`. Retained for continuity
     * with existing rows; it is NOT a document-root path.
     */
    public const LOGICAL_PREFIX = 'uploads/assets/';

    /** Storage root relative to the project dir, outside `public/`. */
    private const STORAGE_ROOT = '/var/assets/';

    /** URL prefix served by the ACL-enforcing delivery route. */
    public const URL_PREFIX = '/cms-api/v1/assets/';

    /** Frontend BFF prefix that proxies to {@see URL_PREFIX}. */
    private const BFF_PREFIX = '/api/assets/';

    /** Any uuid version. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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
     * The logical `file_path` for a new upload stored under its uuid.
     */
    public function filePathForUuid(string $uuid): string
    {
        return self::LOGICAL_PREFIX . $uuid;
    }

    /**
     * The canonical, ACL-enforced delivery URL of an asset.
     */
    public function publicUrl(string $uuid): string
    {
        return self::URL_PREFIX . $uuid;
    }

    /**
     * Resolve a stored `file_path` to an existing file inside the storage
     * root, refusing anything that escapes it (`..`, symlinks). Returns null
     * when the file is missing or unsafe.
     */
    public function resolveForDelivery(string $filePath): ?string
    {
        $real = realpath($this->absolutePath($filePath));
        $root = realpath($this->storageRoot());
        if ($real === false || $root === false || !is_file($real)
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $real;
    }

    /**
     * The uuid of an asset reference stored in content — the delivery URL
     * (absolute or relative), the frontend BFF URL, or a logical `file_path`.
     * Returns null for anything that is not an uploaded asset (external URLs,
     * data URLs, frontend static artwork).
     */
    public function uuidFromReference(string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, 'data:')) {
            return null;
        }

        // Absolute URLs: keep only the path (the host is the backend or the BFF).
        if (preg_match('#^https?://#i', $reference) === 1) {
            $path = parse_url($reference, PHP_URL_PATH);
            if (!is_string($path)) {
                return null;
            }
            $reference = $path;
        }

        $reference = '/' . ltrim(strtok($reference, '?#') ?: '', '/');

        foreach ([self::URL_PREFIX, self::BFF_PREFIX, '/' . self::LOGICAL_PREFIX] as $prefix) {
            if (str_starts_with($reference, $prefix)) {
                $key = substr($reference, strlen($prefix));

                return $this->isUuid($key) ? $key : null;
            }
        }

        return null;
    }

    /**
     * The name a downloaded or attached file gets: the uuid plus the extension
     * of its mime type, so the recipient's system can open it. The stored file
     * itself has no extension.
     */
    public function downloadName(Asset $asset): string
    {
        return self::withExtension($asset->getUuid(), $asset->getMimeType());
    }

    /**
     * `$base` plus the extension of `$mimeType` (`photo` + image/png =
     * `photo.png`); names and stored files carry no extension of their own.
     */
    public static function withExtension(string $base, ?string $mimeType): string
    {
        $extension = $mimeType !== null ? (MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null) : null;

        return $base . ($extension !== null ? '.' . $extension : '');
    }

    public function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * A random RFC 4122 version-4 uuid.
     */
    public function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
