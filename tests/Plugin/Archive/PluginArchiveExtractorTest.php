<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Archive;

use App\Plugin\Archive\PluginArchiveException;
use App\Plugin\Archive\PluginArchiveExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unit tests for `PluginArchiveExtractor`.
 *
 * These tests build a small in-memory `.shplugin` ZIP per case and
 * verify that the extractor accepts well-formed archives and rejects
 * obvious failure modes (wrong extension, oversized, bad magic,
 * zip-slip, missing required entries).
 *
 * The extractor's job is intentionally narrow — it does not verify
 * signatures or recompute checksums (`PluginArchiveValidator` does
 * that), so these tests do not exercise the signing pipeline.
 */
final class PluginArchiveExtractorTest extends TestCase
{
    private string $projectDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->projectDir = sys_get_temp_dir() . '/sh-archive-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->fs->remove($this->projectDir);
        }
    }

    public function testExtractsValidArchive(): void
    {
        $archive = $this->buildArchive($this->validEntries());
        $upload = $this->upload($archive, 'sh-test-1.0.0.shplugin');

        $extractor = new PluginArchiveExtractor($this->projectDir);
        $result = $extractor->extract($upload);

        self::assertSame('sh-test', $result['pluginId']);
        self::assertSame('1.0.0', $result['version']);
        self::assertDirectoryExists($result['stagingDir']);
        self::assertFileExists($result['stagingDir'] . '/plugin.json');
        self::assertFileExists($result['stagingDir'] . '/artifacts/plugin.esm.js');
    }

    public function testRejectsWrongExtension(): void
    {
        $archive = $this->buildArchive($this->validEntries());
        $upload = $this->upload($archive, 'sh-test-1.0.0.zip');

        $this->expectException(PluginArchiveException::class);
        $this->expectExceptionMessageMatches('/\.shplugin extension/');
        (new PluginArchiveExtractor($this->projectDir))->extract($upload);
    }

    public function testRejectsArchiveAboveSizeLimit(): void
    {
        $archive = $this->buildArchive($this->validEntries());
        $upload = $this->upload($archive, 'sh-test-1.0.0.shplugin');

        $extractor = new PluginArchiveExtractor($this->projectDir, 16);
        $this->expectException(PluginArchiveException::class);
        $this->expectExceptionMessageMatches('/maximum allowed/');
        $extractor->extract($upload);
    }

    public function testRejectsNonZipUpload(): void
    {
        $bogus = $this->projectDir . '/bogus.shplugin';
        file_put_contents($bogus, 'not a zip');
        $upload = new UploadedFile($bogus, 'bogus.shplugin', 'application/zip', null, true);

        $this->expectException(PluginArchiveException::class);
        $this->expectExceptionMessageMatches('/ZIP magic bytes/');
        (new PluginArchiveExtractor($this->projectDir))->extract($upload);
    }

    public function testRejectsArchiveMissingRequiredEntry(): void
    {
        $entries = $this->validEntries();
        unset($entries['signature.json']);
        $archive = $this->buildArchive($entries);
        $upload = $this->upload($archive, 'sh-test-1.0.0.shplugin');

        $this->expectException(PluginArchiveException::class);
        $this->expectExceptionMessageMatches('/missing required entries/');
        (new PluginArchiveExtractor($this->projectDir))->extract($upload);
    }

    public function testAcceptsArchiveWithoutCssFile(): void
    {
        // Plugins whose Vite build does not emit a `plugin.css`
        // (admin-only UI, headless services, runtime that imports CSS
        // inline into the JS bundle) are valid. The extractor must NOT
        // require `artifacts/plugin.css`.
        $entries = $this->validEntries();
        unset($entries['artifacts/plugin.css']);
        $entries['artifacts/SHA256SUMS'] = sprintf(
            "%s  plugin.esm.js\n",
            hash('sha256', $entries['artifacts/plugin.esm.js']),
        );
        $archive = $this->buildArchive($entries);
        $upload = $this->upload($archive, 'sh-test-1.0.0.shplugin');

        $extractor = new PluginArchiveExtractor($this->projectDir);
        $result = $extractor->extract($upload);

        self::assertDirectoryExists($result['stagingDir']);
        self::assertFileExists($result['stagingDir'] . '/artifacts/plugin.esm.js');
        self::assertFileDoesNotExist($result['stagingDir'] . '/artifacts/plugin.css');
    }

    public function testRejectsZipSlipPath(): void
    {
        $entries = $this->validEntries();
        $entries['../escape.txt'] = 'pwn';
        $archive = $this->buildArchive($entries);
        $upload = $this->upload($archive, 'sh-test-1.0.0.shplugin');

        $this->expectException(PluginArchiveException::class);
        (new PluginArchiveExtractor($this->projectDir))->extract($upload);
    }

    /** @return array<string,string> */
    private function validEntries(): array
    {
        $manifest = json_encode([
            'id' => 'sh-test',
            'name' => 'Test plugin',
            'version' => '1.0.0',
            'pluginApiVersion' => '1.0',
            'compatibility' => ['selfhelp' => '>=8.0.0 <9.0.0'],
            'backend' => [
                'bundleClass' => 'Test\\Bundle\\TestBundle',
                'composer' => ['package' => 'humdek/sh-test', 'version' => '1.0.0'],
            ],
            'frontend' => [
                'runtime' => ['entrypoint' => 'dist/plugin.esm.js', 'format' => 'esm'],
            ],
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => ['frontendStyles']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $sigJson = json_encode([
            'keyId' => 'dev',
            'signature' => 'AAAA',
            'signedPayload' => '{}',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $esm = "export const register = () => ({ id: 'sh-test' });\n";
        $css = ".sh-test { color: red; }\n";
        $sums = sprintf("%s  plugin.css\n%s  plugin.esm.js\n", hash('sha256', $css), hash('sha256', $esm));

        return [
            'plugin.json' => $manifest,
            'signature.json' => $sigJson,
            'artifacts/SHA256SUMS' => $sums,
            'artifacts/plugin.esm.js' => $esm,
            'artifacts/plugin.css' => $css,
        ];
    }

    /** @param array<string,string> $entries */
    private function buildArchive(array $entries): string
    {
        $path = $this->projectDir . '/build-' . bin2hex(random_bytes(3)) . '.shplugin';
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Cannot create test ZIP at ' . $path);
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function upload(string $path, string $clientName): UploadedFile
    {
        return new UploadedFile($path, $clientName, 'application/zip', null, true);
    }
}
