<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Single source of truth for where asset bytes live on disk and how they are
 * addressed over HTTP.
 *
 * Every asset is stored as `<storage dir>/<uuid>` (`var/assets/` by default,
 * parameter `selfhelp.asset_storage_dir`), OUTSIDE the document root, so
 * the web server cannot serve it directly. Delivery goes through
 * {@see \App\Controller\Api\V1\AssetController}, which applies
 * {@see AssetAccessService} on every request.
 */
class AssetStorageService
{
    /** URL prefix served by the ACL-enforcing delivery route. */
    public const URL_PREFIX = '/cms-api/v1/assets/';

    /** Frontend BFF prefix that proxies to {@see URL_PREFIX}. */
    private const BFF_PREFIX = '/api/assets/';

    /** Any uuid version. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly string $storageDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Absolute path to the storage root (no trailing slash).
     */
    public function storageRoot(): string
    {
        return rtrim($this->storageDir, '/\\');
    }

    /**
     * Absolute location on disk of the asset stored under `$uuid`.
     */
    public function absolutePath(string $uuid): string
    {
        return $this->storageRoot() . '/' . $uuid;
    }

    /**
     * Move an upload next to its final place under a temporary name. Nothing
     * the delivery route can see changes yet: {@see swapIn()} makes it the
     * asset's file with one atomic rename once the database side is ready.
     * Returns the staged file's absolute path.
     */
    public function stage(UploadedFile $file, string $uuid): string
    {
        $root = $this->storageRoot();
        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        $name = '.' . $uuid . '.' . bin2hex(random_bytes(6)) . '.part';
        $file->move($root, $name);

        return $root . '/' . $name;
    }

    /**
     * Make a staged file the asset's file. The rename replaces the old bytes in
     * one step, so a reader sees the old file or the new one, never a half
     * written one; the old bytes are kept under a second name until the caller
     * decides ({@see AssetFileSwap::keep()} or {@see AssetFileSwap::undo()}).
     *
     * @throws \RuntimeException when the file cannot be put in place; the old bytes are untouched then
     */
    public function swapIn(string $stagedPath, string $uuid): AssetFileSwap
    {
        $target = $this->absolutePath($uuid);

        $backup = null;
        if (is_file($target)) {
            $backup = $this->storageRoot() . '/.' . $uuid . '.' . bin2hex(random_bytes(6)) . '.bak';
            // A hard link keeps the old bytes reachable without copying them;
            // copy is the fallback for file systems without links.
            if (!@link($target, $backup) && !@copy($target, $backup)) {
                $this->discardStaged($stagedPath);

                throw new \RuntimeException('Unable to keep the current file of the asset');
            }
        }

        if (!@rename($stagedPath, $target)) {
            if ($backup !== null) {
                @unlink($backup);
            }
            $this->discardStaged($stagedPath);

            throw new \RuntimeException('Unable to store the file of the asset');
        }

        return new AssetFileSwap($target, $backup);
    }

    /**
     * Drop a staged file that will not be used (the transaction failed).
     */
    public function discardStaged(?string $stagedPath): void
    {
        if ($stagedPath !== null && is_file($stagedPath)) {
            @unlink($stagedPath);
        }
    }

    /**
     * Remove the file of an asset whose row is already gone. A file that
     * cannot be removed is only an orphan nobody can reach (no row, so the
     * delivery route answers 404): it is logged, not raised.
     */
    public function delete(string $uuid): void
    {
        $path = $this->absolutePath($uuid);
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('Asset file could not be removed', ['uuid' => $uuid, 'path' => $path]);
        }
    }

    /**
     * The canonical, ACL-enforced delivery URL of an asset.
     */
    public function publicUrl(string $uuid): string
    {
        return self::URL_PREFIX . $uuid;
    }

    /**
     * Resolve the asset stored under `$uuid` to an existing file inside the
     * storage root, refusing anything that is not a uuid or escapes the root
     * (symlinks). Returns null when the file is missing or unsafe.
     */
    public function resolveForDelivery(string $uuid): ?string
    {
        if (!$this->isUuid($uuid)) {
            return null;
        }

        $real = realpath($this->absolutePath($uuid));
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
     * (absolute or relative) or the frontend BFF URL.
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

        foreach ([self::URL_PREFIX, self::BFF_PREFIX] as $prefix) {
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
