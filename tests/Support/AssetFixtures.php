<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Real file bytes for asset upload tests.
 *
 * An upload is accepted only when its content fits its extension (a `.png`
 * must sniff as an image), so test files cannot be `'bytes'` any more. Every
 * builder takes a `$salt` that is appended after the valid part: viewers
 * ignore it, `finfo` still sniffs the right type, and two files with the same
 * salt are byte-identical (a duplicate) while different salts never are.
 */
final class AssetFixtures
{
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** A real 1x1 PNG followed by `$salt`. */
    public static function png(string $salt = ''): string
    {
        return (string) base64_decode(self::ONE_PIXEL_PNG, true) . $salt;
    }

    /** A small PDF followed by `$salt`. */
    public static function pdf(string $salt = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" . $salt;
    }

    /** Plain text: the salt is the text. */
    public static function text(string $salt = 'qa text'): string
    {
        return $salt . "\n";
    }

    /** CSV text: the salt is a cell. */
    public static function csv(string $salt = 'qa'): string
    {
        return "name,value\n" . $salt . ",1\n";
    }

    /** A minimal OOXML word document (a zip) carrying `$salt`. */
    public static function docx(string $salt = ''): string
    {
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . htmlspecialchars($salt) . '</w:t></w:r></w:p></w:body></w:document>',
        ]);
    }

    /**
     * A zip with these entries (name => bytes).
     *
     * @param array<string, string> $entries
     */
    public static function zip(array $entries): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_fixture_zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * The right bytes for a file name, chosen by its extension, so a test that
     * only cares about the name still uploads a file that passes the check.
     */
    public static function bytesFor(string $clientName, string $salt = ''): string
    {
        return match (strtolower(pathinfo($clientName, PATHINFO_EXTENSION))) {
            'png' => self::png($salt),
            'pdf' => self::pdf($salt),
            'docx' => self::docx($salt),
            'csv' => self::csv($salt),
            default => self::text($salt),
        };
    }

    /** A temporary file made of the given bytes, uploaded as `$clientName`. */
    public static function upload(string $clientName, ?string $bytes = null, string $salt = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa_asset');
        if ($path === false) {
            throw new \RuntimeException('No temporary file');
        }
        file_put_contents($path, $bytes ?? self::bytesFor($clientName, $salt));

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
