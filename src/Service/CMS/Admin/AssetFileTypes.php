<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use Symfony\Component\Mime\MimeTypes;

/**
 * Which files an asset may be, and what they are called and delivered as.
 *
 * The file on disk is named by its uuid and has no extension, and sniffing a
 * file's bytes cannot tell a CSV from plain text or a DOCX from a ZIP. So the
 * extension the editor uploaded is checked, stored, and is what decides the
 * stored MIME type and the download name; the sniffed type stays a safety
 * check that the content is plausible for that extension (see
 * docs/developer/09-asset-management.md, "Why the extension is stored").
 */
final class AssetFileTypes
{
    /** Extensions an asset may have. */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', // Images
        'pdf', // Documents
        'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', // Videos
        'mp3', 'wav', 'ogg', // Audio
        'css', 'js', // Web files
        'zip', 'rar', '7z', 'gz', 'tar', // Archives
        'json', 'txt', 'csv', 'tsv', 'rtf', // Text and data
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', // Microsoft Office
        'odt', 'ods', 'odp', // OpenDocument
    ];

    /**
     * What a file of each extension may sniff as. Loose on purpose where the
     * sniff is: text formats come out as `text/plain`, Office and OpenDocument
     * files as `application/zip` (or `application/octet-stream`, see
     * {@see ZIP_BASED}), old Office files as an OLE container.
     */
    private const SNIFFED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
        'pdf' => ['application/pdf'],
        'mp4' => ['video/mp4', 'audio/mp4', 'application/mp4'],
        'avi' => ['video/x-msvideo', 'video/avi', 'video/msvideo', 'application/x-troff-msvideo'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'wmv' => ['video/x-ms-asf', 'video/x-ms-wmv', 'audio/x-ms-wma', 'audio/x-ms-wmv', 'application/vnd.ms-asf'],
        'flv' => ['video/x-flv'],
        'webm' => ['video/webm', 'video/x-matroska', 'audio/webm'],
        'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/mpeg3', 'audio/x-mpeg-3'],
        'wav' => ['audio/x-wav', 'audio/wav', 'audio/vnd.wave', 'audio/wave'],
        'ogg' => ['audio/ogg', 'application/ogg', 'video/ogg'],
        'css' => ['text/css', 'text/plain', self::EMPTY_FILE],
        'js' => ['text/javascript', 'application/javascript', 'application/x-javascript', 'text/plain', self::EMPTY_FILE],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'gz' => ['application/gzip', 'application/x-gzip'],
        'tar' => ['application/x-tar'],
        'json' => ['application/json', 'text/plain', self::EMPTY_FILE],
        'txt' => ['text/plain', self::EMPTY_FILE],
        'csv' => ['text/csv', 'application/csv', 'text/plain', self::EMPTY_FILE],
        'tsv' => ['text/tab-separated-values', 'text/csv', 'text/plain', self::EMPTY_FILE],
        'rtf' => ['text/rtf', 'application/rtf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-corrupt'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    /** What an empty file sniffs as. Fine for a text format, a broken file for anything else. */
    private const EMPTY_FILE = 'application/x-empty';

    /**
     * Formats that are ZIP archives. `application/octet-stream` (an unknown
     * binary) is plausible for them only when the bytes really start like a
     * ZIP, so an executable renamed to `.docx` is still refused.
     */
    private const ZIP_BASED = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    /**
     * The first canonical type of an extension is not always the one a client
     * can play: `mp4` is `application/mp4` and `wmv` is `audio/x-ms-wmv`
     * there, which would turn videos into a download.
     */
    private const MIME_OVERRIDES = [
        'mp4' => 'video/mp4',
        'wmv' => 'video/x-ms-wmv',
    ];

    /**
     * Types a browser may show instead of downloading; everything else is
     * delivered as an attachment.
     */
    private const INLINE_MIME_PREFIXES = ['image/', 'audio/', 'video/'];
    private const INLINE_MIME_TYPES = ['application/pdf', 'text/plain'];

    public static function isAllowed(string $extension): bool
    {
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Whether the sniffed type (and, for a ZIP-based format, the first bytes
     * at `$path`) is plausible for the extension. An extension outside the
     * allowlist and an unknown sniff are never plausible.
     */
    public static function contentMatches(string $extension, ?string $sniffedMimeType, string $path): bool
    {
        $accepted = self::SNIFFED[$extension] ?? null;
        if ($accepted === null || $sniffedMimeType === null) {
            return false;
        }
        if (!in_array($sniffedMimeType, $accepted, true)) {
            return false;
        }

        return $sniffedMimeType !== 'application/octet-stream'
            || !in_array($extension, self::ZIP_BASED, true)
            || self::startsLikeZip($path);
    }

    /**
     * The MIME type to store for an upload: the canonical type of its
     * extension (a CSV is `text/csv` whatever the sniff said), or the sniffed
     * type for an extension the library does not know.
     */
    public static function mimeTypeFor(string $extension, ?string $fallback): ?string
    {
        return self::MIME_OVERRIDES[$extension]
            ?? MimeTypes::getDefault()->getMimeTypes($extension)[0]
            ?? $fallback;
    }

    /**
     * The extension for a MIME type (`image/png` is `png`), for assets that
     * were stored without one.
     */
    public static function extensionForMimeType(?string $mimeType): ?string
    {
        return $mimeType !== null ? (MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null) : null;
    }

    /**
     * Whether a file of this type may be shown by the browser (`inline`)
     * rather than saved (`attachment`).
     */
    public static function isInline(?string $mimeType): bool
    {
        if ($mimeType === null) {
            return false;
        }
        if (in_array($mimeType, self::INLINE_MIME_TYPES, true)) {
            return true;
        }
        foreach (self::INLINE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function startsLikeZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = fread($handle, 4);
        fclose($handle);

        // Local file header, or the end record of an empty archive.
        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }
}
