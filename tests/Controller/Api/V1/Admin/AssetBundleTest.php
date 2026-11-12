<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AssetExportImportService;
use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Asset bundles (ZIP with manifest.json + files/<uuid>) over the API.
 *
 * - The folder an asset sits in travels as a list of names and is recreated
 *   (or reused) on import; bundles made before folders travelled still import.
 * - An uploaded bundle is untrusted: paths that leave `files/`, entries the
 *   manifest does not name, bundles with more entries than the limit, an
 *   oversized manifest and archives that are not archives are all refused, and
 *   nothing is left behind in the temporary directory.
 */
#[TestGroup('security')]
final class AssetBundleTest extends QaWebTestCase
{
    private ?string $token = null;

    /** @var list<string> uuids whose files the test may have written */
    private array $uuids = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $storage = $this->service(AssetStorageService::class);
        foreach ($this->uuids as $uuid) {
            @unlink($storage->absolutePath($uuid));
        }
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testExportCarriesTheFolderPathAsNamesAndImportRecreatesIt(): void
    {
        $parent = $this->folder('qa-bundle-parent');
        $child = $this->folder('qa-bundle-child', $parent);
        $asset = $this->upload('qa-bundle-nested.png', ['folder_id' => (string) $child]);
        $uuid = $this->asString($asset['uuid']);

        $zip = $this->export([$this->asInt($asset['id'])]);
        self::assertSame(
            ['qa-bundle-parent', 'qa-bundle-child'],
            $this->manifestEntry($zip, $uuid)['folder_path'],
            'Folders travel as names from the top down, not as instance-specific ids'
        );

        // A different instance has no such folders: the asset and both folders go.
        $this->request('DELETE', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']));
        // (only an empty folder can be deleted: the child first, then its parent)
        $this->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $child);
        $this->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $parent);
        self::assertNull($this->assetByUuid($uuid));
        self::assertSame([], $this->foldersNamed('qa-bundle-parent'));

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $this->importOk($zip));

        $restored = $this->assetByUuid($uuid);
        self::assertNotNull($restored);
        $recreatedChild = $this->folderById($this->asInt($restored['folder_id']));
        self::assertSame('qa-bundle-child', $recreatedChild['name']);
        $recreatedParent = $this->folderById($this->asInt($recreatedChild['parent_id']));
        self::assertSame('qa-bundle-parent', $recreatedParent['name']);
        self::assertNull($recreatedParent['parent_id'], 'The first name of the path is a top-level folder');
    }

    public function testImportReusesFoldersThatAlreadyExistInsteadOfDuplicatingThem(): void
    {
        $parent = $this->folder('qa-bundle-reuse-parent');
        $child = $this->folder('qa-bundle-reuse-child', $parent);
        $asset = $this->upload('qa-bundle-reuse.png', ['folder_id' => (string) $child]);
        $uuid = $this->asString($asset['uuid']);
        $zip = $this->export([$this->asInt($asset['id'])]);

        // Only the asset goes: the folders stay and must be used again.
        $this->request('DELETE', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']));
        self::assertSame(1, $this->importOk($zip)['imported']);

        $restored = $this->assetByUuid($uuid);
        self::assertNotNull($restored);
        self::assertSame($child, $restored['folder_id'], 'The asset is back in the folder it was in');
        self::assertCount(1, $this->foldersNamed('qa-bundle-reuse-parent'));
        self::assertCount(1, $this->foldersNamed('qa-bundle-reuse-child'));
    }

    public function testABundleMadeBeforeFoldersTravelledStillImportsIntoTheTopLevel(): void
    {
        $uuid = $this->newUuid();
        $zip = $this->bundle([$this->entry($uuid)]); // no `folder_path` key at all

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $this->importOk($zip));

        $asset = $this->assetByUuid($uuid);
        self::assertNotNull($asset);
        self::assertNull($asset['folder_id']);
    }

    /**
     * A13: an exported file is named like a download, `<name>-<uuid>.<ext>`,
     * the manifest carries the extension and names the file in `bundle_path`,
     * and importing it again keeps the extension.
     */
    public function testExportNamesTheFileLikeADownloadAndCarriesTheExtension(): void
    {
        $asset = $this->upload('qa-bundle-named.png');
        $uuid = $this->asString($asset['uuid']);
        $zip = $this->export([$this->asInt($asset['id'])]);

        $entry = $this->manifestEntry($zip, $uuid);
        self::assertSame('png', $entry['extension']);
        self::assertSame('files/qa-bundle-named-' . $uuid . '.png', $entry['bundle_path']);

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip, \ZipArchive::RDONLY));
        self::assertNotFalse($archive->locateName('files/qa-bundle-named-' . $uuid . '.png'), 'The archive entry has the download name');
        $archive->close();

        $this->request('DELETE', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']));
        self::assertSame(1, $this->importOk($zip)['imported']);
        $restored = $this->assetByUuid($uuid);
        self::assertNotNull($restored);
        self::assertSame('png', $restored['extension']);
        self::assertSame('qa-bundle-named-' . $uuid . '.png', $restored['file_name']);
    }

    /** A bundle made before the extension travelled gets it from the entry's mime type. */
    public function testABundleWithoutExtensionTakesItFromTheMimeType(): void
    {
        $uuid = $this->newUuid();
        $entry = $this->entry($uuid);
        unset($entry['extension']);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $this->importOk($this->bundle([$entry])));
        $asset = $this->assetByUuid($uuid);
        self::assertNotNull($asset);
        self::assertSame('png', $asset['extension']);
    }

    public function testInvalidFolderPathsAreReportedForTheirEntryAndNothingIsImportedForIt(): void
    {
        $invalid = [
            'a string' => 'qa-folder',
            'an empty name' => [''],
            'a blank name' => ['   '],
            'a name that is not text' => [['qa-folder']],
            'not a list' => ['top' => 'qa-folder'],
            'deeper than allowed' => array_fill(0, AssetExportImportService::MAX_FOLDER_DEPTH + 1, 'qa-folder'),
        ];

        $uuids = [];
        $entries = [];
        foreach ($invalid as $case => $path) {
            $uuid = $this->newUuid();
            $uuids[$case] = $uuid;
            $entries[] = $this->entry($uuid, ['folder_path' => $path]);
        }

        $result = $this->importOk($this->bundle($entries));

        self::assertSame(0, $result['imported']);
        self::assertSame(array_values($uuids), array_map(static fn (mixed $error): mixed => is_array($error) ? ($error['file'] ?? null) : null, $this->asList($result['errors'])));
        foreach ($this->asList($result['errors']) as $error) {
            self::assertSame('Invalid folder path', $this->asArray($error)['error']);
        }
        foreach ($uuids as $case => $uuid) {
            self::assertNull($this->assetByUuid($uuid), "Nothing was imported for: {$case}");
        }
        self::assertSame([], $this->foldersNamed('qa-folder'), 'No folder was created for a refused entry');
    }

    public function testManifestPathsOutsideTheFilesFolderAreRefused(): void
    {
        $paths = ['../evil', 'files/../evil', 'manifest.json', 'files/a/b', '/etc/passwd', 'files\\evil', '', 'files/', 'filesevil'];

        $entries = [];
        foreach ($paths as $path) {
            $entries[] = $this->entry($this->newUuid(), ['bundle_path' => $path]);
        }

        $result = $this->importOk($this->bundle($entries, ['evil' => 'x']));

        self::assertSame(0, $result['imported']);
        self::assertCount(count($paths), $this->asList($result['errors']));
        foreach ($this->asList($result['errors']) as $error) {
            self::assertSame('Invalid bundle path', $this->asArray($error)['error']);
        }
    }

    public function testEntriesTheManifestDoesNotNameAreNeverWritten(): void
    {
        $uuid = $this->newUuid();
        $temporaryBefore = $this->importWorkDirs();
        $storageBefore = $this->storageFiles();

        $zip = $this->bundle([$this->entry($uuid)], [
            'files/unnamed' => 'not in the manifest',
            'evil.php' => '<?php echo 1;',
            '../escape.txt' => 'zip slip',
        ]);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $this->importOk($zip));

        self::assertSame($this->sorted(array_merge($storageBefore, [$uuid])), $this->storageFiles(), 'Only the file the manifest names reached the storage');
        self::assertFileDoesNotExist(dirname(sys_get_temp_dir()) . '/escape.txt');
        self::assertFileDoesNotExist(sys_get_temp_dir() . '/escape.txt');
        self::assertSame($temporaryBefore, $this->importWorkDirs(), 'The import work directory is removed again');
    }

    public function testABundleWithMoreEntriesThanTheLimitIsRefusedBeforeAnythingIsRead(): void
    {
        $files = [];
        for ($i = 0; $i <= AssetExportImportService::MAX_ASSETS; $i++) {
            $files['files/qa-' . $i] = 'x';
        }
        $files['manifest.json'] = $this->manifestJson([]);

        $envelope = $this->importRaw($this->zipOf($files));

        $this->assertEnvelope400($envelope);
        self::assertStringContainsString('at most ' . AssetExportImportService::MAX_ASSETS . ' assets', $this->errorText($envelope));
    }

    public function testAManifestListingMoreAssetsThanTheLimitIsRefused(): void
    {
        $entries = [];
        for ($i = 0; $i <= AssetExportImportService::MAX_ASSETS; $i++) {
            $entries[] = $this->entry($this->newUuid());
        }

        $envelope = $this->importRaw($this->zipOf(['manifest.json' => $this->manifestJson($entries)]));

        $this->assertEnvelope400($envelope);
        self::assertStringContainsString('at most ' . AssetExportImportService::MAX_ASSETS . ' assets', $this->errorText($envelope));
    }

    public function testAnOversizedManifestIsRefused(): void
    {
        $manifest = (string) json_encode(['bundle_type' => 'selfhelp/asset-bundle', 'bundle_version' => '2.0', 'assets' => [], 'padding' => str_repeat('x', AssetExportImportService::MAX_MANIFEST_BYTES)]);

        $envelope = $this->importRaw($this->zipOf(['manifest.json' => $manifest]));

        $this->assertEnvelope400($envelope);
        self::assertStringContainsString('too large', $this->errorText($envelope));
    }

    public function testBundlesThatAreNotBundlesAreRefused(): void
    {
        $cases = [
            'no manifest' => [$this->zipOf(['files/x' => 'x']), 'missing manifest.json'],
            'a manifest that is not JSON' => [$this->zipOf(['manifest.json' => 'not json']), 'not valid JSON'],
            'another bundle type' => [$this->zipOf(['manifest.json' => (string) json_encode(['bundle_type' => 'selfhelp/other', 'bundle_version' => '2.0', 'assets' => []])]), 'Unsupported bundle type'],
            'an old bundle version' => [$this->zipOf(['manifest.json' => (string) json_encode(['bundle_type' => 'selfhelp/asset-bundle', 'bundle_version' => '1.0', 'assets' => []])]), 'Unsupported bundle version'],
        ];

        foreach ($cases as $case => [$zip, $expected]) {
            $envelope = $this->importRaw($zip);
            $this->assertEnvelope400($envelope);
            self::assertStringContainsString($expected, $this->errorText($envelope), $case);
        }

        $notAZip = $this->tempFile('this is not an archive');
        $envelope = $this->importRaw($notAZip);
        $this->assertEnvelope400($envelope);
        self::assertStringContainsString('not a valid ZIP', $this->errorText($envelope));
    }

    public function testExportLeavesNoTemporaryArchiveBehind(): void
    {
        $asset = $this->upload('qa-bundle-temp.png');
        $before = $this->exportArchives();

        $zip = $this->export([$this->asInt($asset['id'])]);

        self::assertSame($before, $this->exportArchives(), 'The archive is deleted once it is sent');
        self::assertSame($this->asString($asset['uuid']), $this->manifestEntry($zip, $this->asString($asset['uuid']))['uuid']);
    }

    // -- helpers -------------------------------------------------------------

    private function token(): string
    {
        return $this->token ??= $this->loginAsQaAdmin();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token()];
    }

    private function request(string $method, string $uri): void
    {
        $this->client->request($method, $uri, [], [], $this->headers());
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    private function newUuid(): string
    {
        $uuid = $this->service(AssetStorageService::class)->generateUuid();
        $this->uuids[] = $uuid;

        return $uuid;
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_bundle');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function upload(string $name, array $fields = []): array
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets', $fields, ['file' => new UploadedFile($this->tempFile(AssetFixtures::png('qa-bytes')), $name, null, null, true)], $this->headers());
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $asset = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()), 201);
        $this->uuids[] = $this->asString($asset['uuid']);

        return $asset;
    }

    private function folder(string $name, ?int $parentId = null): int
    {
        $body = ['name' => $name] + ($parentId !== null ? ['parent_id' => $parentId] : []);
        $folders = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', $body, $this->token()));
        foreach ($this->asList($folders) as $row) {
            $row = $this->asArray($row);
            if ($row['name'] === $name) {
                return $this->asInt($row['id']);
            }
        }
        self::fail('Saved folder missing from the folder list');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function folders(): array
    {
        return array_map(
            fn (mixed $row): array => $this->asArray($row),
            $this->asList($this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/asset-folders', null, $this->token())))
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function foldersNamed(string $name): array
    {
        return array_values(array_filter($this->folders(), static fn (array $row): bool => $row['name'] === $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function folderById(int $id): array
    {
        foreach ($this->folders() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail("Folder {$id} does not exist");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetByUuid(string $uuid): ?array
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/assets?pageSize=1000', null, $this->token()));
        foreach ($this->asList($data['assets']) as $row) {
            $row = $this->asArray($row);
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Export the assets and return the path of a copy of the archive.
     *
     * @param list<int> $ids
     */
    private function export(array $ids): string
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets/export', [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['asset_ids' => $ids]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // A file response: its bytes are in the browser's copy, not getContent().
        return $this->tempFile((string) $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestEntry(string $zipPath, string $uuid): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::RDONLY));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        foreach ($this->asList($this->asArray($manifest)['assets']) as $entry) {
            $entry = $this->asArray($entry);
            if ($entry['uuid'] === $uuid) {
                return $entry;
            }
        }
        self::fail("{$uuid} is not in the manifest");
    }

    /**
     * Import a copy (an uploaded file in test mode is moved) and return the envelope.
     *
     * @return array<string, mixed>
     */
    private function importRaw(string $zipPath): array
    {
        $copy = $this->tempFile((string) file_get_contents($zipPath));

        $this->client->request('POST', '/cms-api/v1/admin/assets/import', [], ['file' => new UploadedFile($copy, 'qa-bundle.zip', 'application/zip', null, true)], $this->headers());

        return $this->decode($this->client->getResponse());
    }

    /**
     * @return array<string, mixed> the `data` of a successful import
     */
    private function importOk(string $zipPath): array
    {
        return $this->assertEnvelopeSuccess($this->importRaw($zipPath));
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function errorText(array $envelope): string
    {
        return (string) json_encode([$envelope['error'] ?? null, $envelope['message'] ?? null]);
    }

    /**
     * A manifest entry for a file `files/<uuid>`; `$override` replaces keys.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function entry(string $uuid, array $override = []): array
    {
        return $override + [
            'uuid' => $uuid,
            'name' => 'qa-bundle-' . substr($uuid, 0, 8),
            'alt' => null,
            'mime_type' => 'image/png',
            'extension' => 'png',
            'asset_type' => 'Asset',
            'groups' => [],
            'bundle_path' => 'files/' . $uuid,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function manifestJson(array $entries): string
    {
        return (string) json_encode([
            'bundle_type' => 'selfhelp/asset-bundle',
            'bundle_version' => '2.0',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'assets' => $entries,
        ]);
    }

    /**
     * A v2.0 bundle: the manifest, the file of every entry that has a
     * `files/<uuid>` path, and any `$extra` archive entries (name => bytes).
     *
     * @param list<array<string, mixed>> $entries
     * @param array<string, string> $extra
     */
    private function bundle(array $entries, array $extra = []): string
    {
        $files = ['manifest.json' => $this->manifestJson($entries)];
        foreach ($entries as $entry) {
            $path = $entry['bundle_path'] ?? null;
            if (is_string($path) && preg_match('#^files/[^/\\\\]+$#', $path) === 1) {
                $files[$path] = AssetFixtures::png('qa-bytes');
            }
        }

        return $this->zipOf($files + $extra);
    }

    /**
     * @param array<string, string> $files archive entry name => bytes
     */
    private function zipOf(array $files): string
    {
        $path = $this->tempFile('');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $bytes) {
            // An entry name the library refuses (some hostile names) is simply not in the archive.
            $zip->addFromString((string) $name, $bytes);
        }
        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * @return list<string>
     */
    private function storageFiles(): array
    {
        return $this->sorted(array_values(array_diff(scandir($this->service(AssetStorageService::class)->storageRoot()) ?: [], ['.', '..'])));
    }

    /**
     * @return list<string>
     */
    private function importWorkDirs(): array
    {
        return $this->sorted(glob(sys_get_temp_dir() . '/asset-import-*') ?: []);
    }

    /**
     * @return list<string>
     */
    private function exportArchives(): array
    {
        return $this->sorted(glob(sys_get_temp_dir() . '/asset-export-*.zip') ?: []);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
