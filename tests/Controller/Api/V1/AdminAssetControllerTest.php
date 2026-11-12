<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\Factories\GroupFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin asset management: uuid storage (no overwrite), edit + replace under
 * the same uuid, default alt, per-asset view groups (none = public), folders,
 * and bulk group changes and deletes.
 */
class AdminAssetControllerTest extends BaseControllerTest
{
    /** Custom group the tests restrict assets to. */
    private const QA_GROUP = 'qa_asset_group';

    /** @var list<int> */
    private array $createdAssetIds = [];

    public function testListReturnsAssetsWithTheirUuidUrlAltAndGroups(): void
    {
        $created = $this->upload('qa-list.png', ['alt' => 'qa list alt']);

        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa list alt'], [], $this->adminHeaders());

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = $this->asArray($this->decodeArray()['data']);
        $pagination = $this->asArray($payload['pagination']);
        $this->assertSame(1, $pagination['total']);

        $asset = $this->asArray($this->asList($payload['assets'])[0]);
        $this->assertSame($created['id'], $asset['id']);
        $this->assertSame('/cms-api/v1/assets/' . $this->asString($created['uuid']), $asset['url']);
        $this->assertSame('qa list alt', $asset['alt']);
        $this->assertArrayNotHasKey('folder', $asset);
        // The admin role views and manages every asset; per-caller flags for the
        // other roles are covered in AssetManagementAccessTest.
        $this->assertTrue($asset['can_view']);
        $this->assertTrue($asset['can_update']);
        $this->assertTrue($asset['can_delete']);
        $this->assertTrue($created['can_view'] && $created['can_update'] && $created['can_delete'], 'The upload response carries the flags too');
    }

    /**
     * The uuid is the name on disk (no extension). The extension is stored
     * next to it, and the delivered file is named `<name>-<uuid>.<ext>`: the
     * label, the uuid that makes it unique, and the extension that gives it
     * the right program.
     */
    public function testUploadStoresUnderTheUuidKeepsTheNameAsLabelIsPublicAndManagedByTheUploader(): void
    {
        $asset = $this->upload('qa-upload.png');
        $uuid = $this->asString($asset['uuid']);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
        $this->assertSame('png', $asset['extension']);
        $this->assertSame('image/png', $asset['mime_type']);
        $this->assertSame('qa-upload-' . $uuid . '.png', $asset['file_name']);
        $this->assertSame('qa-upload', $asset['name'], 'The default name drops the extension');
        $this->assertArrayNotHasKey('file_path', $asset);
        $this->assertSame([], $this->groupNames($asset), 'A new upload has no groups, so it is public');
        $this->assertNotContains('admin', array_map(
            fn (mixed $role): mixed => $this->asArray($role)['name'],
            $this->asList($asset['roles'])
        ), 'The admin role manages every asset without a grant, so it is never listed');

        $this->deliveredBody($asset);
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('qa-upload-' . $uuid . '.png', $disposition);
    }

    /**
     * Uploading a file whose name is already taken must never replace the
     * existing asset: both live side by side under different uuids.
     */
    public function testUploadingTheSameNameTwiceKeepsBothAssets(): void
    {
        $first = $this->upload('qa-same.png');
        $second = $this->upload('qa-same.png');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotSame($first['uuid'], $second['uuid']);
        $this->assertSame(AssetFixtures::png('first-bytes'), $this->deliveredBody($first));
        $this->assertSame(AssetFixtures::png('first-bytes'), $this->deliveredBody($second));
    }

    public function testUploadWithExplicitGroupsAndWithNoGroups(): void
    {
        $restricted = $this->upload('qa-restricted.png', ['group_ids' => (string) $this->groupId(self::QA_GROUP)]);
        $this->assertSame([self::QA_GROUP], $this->groupNames($restricted));

        $public = $this->upload('qa-public.png', ['group_ids' => '']);
        $this->assertSame([], $this->groupNames($public));
    }

    public function testCreateAssetInvalidFileType(): void
    {
        $file = $this->tempUpload('qa-bad.exe', 'nope');

        $this->client->request('POST', '/cms-api/v1/admin/assets', [], ['file' => $file], $this->adminHeaders());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateAssetMissingFile(): void
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets', ['alt' => 'qa none'], [], $this->adminHeaders());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testGetAssetByIdAndNotFound(): void
    {
        $asset = $this->upload('qa-get.png');

        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']), [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertSame($asset['uuid'], $this->asArray($this->decodeArray()['data'])['uuid']);

        $this->client->request('GET', '/cms-api/v1/admin/assets/2147483600', [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testEditRenamesTheLabelSetsAltAndReplacesGroupsAndKeepsTheUuid(): void
    {
        $asset = $this->upload('qa-edit.png');
        $groupId = $this->groupId(self::QA_GROUP);

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']),
            ['name' => 'qa edited', 'alt' => 'qa edited alt', 'group_ids' => (string) $groupId],
            [],
            $this->adminHeaders()
        );

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $edited = $this->asArray($this->decodeArray()['data']);
        $this->assertSame('qa edited', $edited['name']);

        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa edited'], [], $this->adminHeaders());
        $found = $this->asList($this->asArray($this->decodeArray()['data'])['assets']);
        $this->assertSame([$asset['id']], array_map(fn (mixed $row): mixed => $this->asArray($row)['id'], $found), 'Search finds the asset by its name');
        $this->assertSame('qa edited alt', $edited['alt']);
        $this->assertSame([self::QA_GROUP], $this->groupNames($edited));
        $this->assertSame($asset['uuid'], $edited['uuid']);
    }

    /**
     * Replace keeps the uuid (and so the URL every page already uses) and
     * serves the new bytes from it.
     */
    public function testReplacingTheFileKeepsTheUuidAndServesTheNewBytes(): void
    {
        $asset = $this->upload('qa-replace.png');

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']),
            [],
            ['file' => $this->tempUpload('qa-replace-v2.png', 'second-bytes')],
            $this->adminHeaders()
        );

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $replaced = $this->asArray($this->decodeArray()['data']);
        $this->assertSame($asset['uuid'], $replaced['uuid']);
        $this->assertSame($asset['url'], $replaced['url']);
        $this->assertSame(AssetFixtures::png('second-bytes'), $this->deliveredBody($replaced));
    }

    public function testBulkAddAndRemoveAGroup(): void
    {
        $first = $this->upload('qa-bulk-1.png');
        $second = $this->upload('qa-bulk-2.png');
        $groupId = $this->groupId(self::QA_GROUP);
        $ids = [$this->asInt($first['id']), $this->asInt($second['id'])];

        $this->bulkGroups($ids, [$groupId], 'add');
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertSame(2, $this->asArray($this->decodeArray()['data'])['updated']);
        $this->assertContains(self::QA_GROUP, $this->groupNames($this->fetch($ids[0])));
        $this->assertContains(self::QA_GROUP, $this->groupNames($this->fetch($ids[1])));

        $this->bulkGroups($ids, [$groupId], 'remove');
        $this->assertNotContains(self::QA_GROUP, $this->groupNames($this->fetch($ids[0])));
    }

    public function testCreateMultipleAssetsSuccess(): void
    {
        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $files[] = $this->tempUpload("qa-multi-$i.png", "multi $i");
        }

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [],
            ['files' => $files],
            $this->adminHeaders()
        );

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $payload = $this->asArray($this->decodeArray()['data']);
        $this->assertSame(3, $payload['successful_uploads']);
        foreach ($this->asList($payload['uploaded']) as $raw) {
            $asset = $this->asArray($raw);
            $this->assertStringStartsWith('qa-multi-', $this->asString($asset['name']));
            $this->createdAssetIds[] = $this->asInt($asset['id']);
        }
    }

    /**
     * A bundle carries uuid, name, alt and group names. Re-importing it where the
     * uuid exists skips (never overwrites); after a delete it restores the
     * asset under the same uuid, so page URLs keep working.
     */
    public function testExportImportRoundTripKeepsTheUuidAndNeverOverwrites(): void
    {
        $asset = $this->upload('qa-bundle.png', ['alt' => 'qa bundle alt', 'group_ids' => (string) $this->groupId(self::QA_GROUP)]);
        $id = $this->asInt($asset['id']);

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/export',
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['asset_ids' => [$id]])
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'qa_bundle');
        // A file response: its bytes are in the browser's copy, not getContent().
        file_put_contents($zipPath, (string) $this->client->getInternalResponse()->getContent());

        $this->assertSame(['imported' => 0, 'skipped' => 1, 'errors' => []], $this->importBundle($zipPath));

        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $this->adminHeaders());
        $this->createdAssetIds = array_values(array_diff($this->createdAssetIds, [$id]));

        $this->assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $this->importBundle($zipPath));

        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa bundle alt'], [], $this->adminHeaders());
        $restored = $this->asArray($this->asList($this->asArray($this->decodeArray()['data'])['assets'])[0]);
        $this->createdAssetIds[] = $this->asInt($restored['id']);
        $this->assertSame($asset['uuid'], $restored['uuid']);
        $this->assertSame('qa-bundle', $restored['name']);
        $this->assertSame([self::QA_GROUP], $this->groupNames($restored));
        $this->assertSame(AssetFixtures::png('first-bytes'), $this->deliveredBody($restored));
    }

    /**
     * An entry naming a group this instance does not have is refused and
     * reported, not imported without the group: dropping the group would
     * silently publish a restricted asset (no groups = public). The other
     * entries of the same bundle still import. Same rule as the users CSV
     * import.
     */
    public function testImportRefusesAnAssetThatNamesAGroupMissingOnThisInstance(): void
    {
        /** @var AssetStorageService $storage */
        $storage = static::getContainer()->get(AssetStorageService::class);
        $restrictedUuid = $storage->generateUuid();
        $publicUuid = $storage->generateUuid();

        $zipPath = $this->buildBundle([
            ['uuid' => $restrictedUuid, 'name' => 'qa-import-restricted', 'groups' => ['qa_group_missing_here']],
            ['uuid' => $publicUuid, 'name' => 'qa-import-public', 'groups' => []],
        ]);

        $result = $this->importBundle($zipPath);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(
            [['file' => $restrictedUuid, 'error' => 'Unknown group name(s): qa_group_missing_here']],
            $result['errors']
        );

        $this->assertSame([], $this->listedIds(['search' => 'qa-import-restricted']), 'The refused asset does not exist, not even as a public one');
        $imported = $this->listedIds(['search' => 'qa-import-public']);
        $this->assertCount(1, $imported);
        $this->createdAssetIds[] = $this->asInt($imported[0]);
    }

    public function testDeleteAssetRemovesIt(): void
    {
        $asset = $this->upload('qa-delete.png');
        $id = $this->asInt($asset['id']);

        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->createdAssetIds = array_values(array_diff($this->createdAssetIds, [$id]));

        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testBulkDeleteRemovesTheSelectedAssets(): void
    {
        $first = $this->upload('qa-bulk-delete-1.png');
        $second = $this->upload('qa-bulk-delete-2.png');
        $ids = [$this->asInt($first['id']), $this->asInt($second['id'])];

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/bulk-delete',
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['asset_ids' => $ids])
        );

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertSame(2, $this->asArray($this->decodeArray()['data'])['deleted']);
        $this->createdAssetIds = array_values(array_diff($this->createdAssetIds, $ids));
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $ids[0], [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Folders only organise: they nest, an asset can sit in one and be listed
     * by it, a folder cannot move into its own subfolder, and only an empty
     * folder (no subfolder, no asset) can be deleted. More folder rules are in
     * AssetFolderTreeTest.
     */
    public function testFoldersNestFilterTheListAndOnlyAnEmptyFolderIsDeleted(): void
    {
        $parent = $this->saveFolder(['name' => 'qa-folder-parent']);
        $child = $this->saveFolder(['name' => 'qa-folder-child', 'parent_id' => $parent]);

        $asset = $this->upload('qa-in-folder.png', ['folder_id' => (string) $child]);
        $this->assertSame($child, $asset['folder_id']);

        $this->client->request('GET', '/cms-api/v1/admin/assets', ['folder_id' => (string) $child], [], $this->adminHeaders());
        $listed = $this->asList($this->asArray($this->decodeArray()['data'])['assets']);
        $this->assertSame([$asset['id']], array_map(fn (mixed $row): mixed => $this->asArray($row)['id'], $listed));

        $this->client->request(
            'PUT',
            '/cms-api/v1/admin/asset-folders/' . $parent,
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['name' => 'qa-folder-parent', 'parent_id' => $child])
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), 'A folder cannot move into its own subfolder');

        $this->client->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $parent, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), 'A folder with a subfolder is not deleted');
        $this->client->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $child, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), 'A folder with an asset is not deleted');
        $this->assertSame($child, $this->fetch($this->asInt($asset['id']))['folder_id'], 'The refused delete left the asset where it is');

        $this->bulkMove(['asset_ids' => [$asset['id']], 'folder_id' => null]);
        $this->client->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $child, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->client->request('DELETE', '/cms-api/v1/admin/asset-folders/' . $parent, [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $remaining = array_map(fn (mixed $row): mixed => $this->asArray($row)['id'], $this->asList($this->decodeArray()['data']));
        $this->assertNotContains($child, $remaining);
        $this->assertNotContains($parent, $remaining);
    }

    /**
     * Browsing a folder lists only the assets directly in it (`folder_id=0`
     * = the assets in no folder), and the folder list counts each folder's
     * own assets.
     */
    public function testBrowsingAFolderListsOnlyItsOwnAssetsAndTheFolderListCountsThem(): void
    {
        $parent = $this->saveFolder(['name' => 'qa-browse-parent']);
        $child = $this->saveFolder(['name' => 'qa-browse-child', 'parent_id' => $parent]);
        $loose = $this->upload('qa-browse-loose.png');
        $inParent = $this->upload('qa-browse-in-parent.png', ['folder_id' => (string) $parent]);
        $inChild = $this->upload('qa-browse-in-child.png', ['folder_id' => (string) $child]);

        $topLevel = $this->listedIds(['folder_id' => '0']);
        $this->assertContains($loose['id'], $topLevel);
        $this->assertNotContains($inParent['id'], $topLevel, 'Assets in a folder are not at the top level');
        $this->assertNotContains($inChild['id'], $topLevel);

        $this->assertSame([$inParent['id']], $this->listedIds(['folder_id' => (string) $parent]), 'A folder lists its own assets, not its subfolders\'');

        $this->client->request('GET', '/cms-api/v1/admin/asset-folders', [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $counts = [];
        $subtreeCounts = [];
        foreach ($this->asList($this->decodeArray()['data']) as $row) {
            $row = $this->asArray($row);
            $counts[$this->asInt($row['id'])] = $row['asset_count'];
            $subtreeCounts[$this->asInt($row['id'])] = $row['subtree_asset_count'];
        }
        $this->assertSame(1, $counts[$parent]);
        $this->assertSame(1, $counts[$child]);
        $this->assertSame(2, $subtreeCounts[$parent], 'A folder counts its own assets and those below it');
        $this->assertSame(1, $subtreeCounts[$child]);
    }

    /**
     * Bulk move puts the selected assets into one folder, and back at the top
     * level with `folder_id: null`; an unknown folder is rejected.
     */
    public function testBulkMoveMovesTheSelectedAssetsIntoAFolderAndBack(): void
    {
        $folder = $this->saveFolder(['name' => 'qa-move-target']);
        $first = $this->upload('qa-move-1.png');
        $second = $this->upload('qa-move-2.png');
        $ids = [$this->asInt($first['id']), $this->asInt($second['id'])];

        $this->bulkMove(['asset_ids' => $ids, 'folder_id' => $folder]);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->assertSame(2, $this->asArray($this->decodeArray()['data'])['moved']);
        $this->assertEqualsCanonicalizing($ids, $this->listedIds(['folder_id' => (string) $folder]));

        $this->bulkMove(['asset_ids' => $ids, 'folder_id' => null]);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertNull($this->fetch($ids[0])['folder_id'], 'null moves them back to the top level');
        $this->assertSame([], $this->listedIds(['folder_id' => (string) $folder]));

        $this->bulkMove(['asset_ids' => $ids, 'folder_id' => 2147483600]);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), 'Unknown folder');
    }

    public function testUnauthorizedAccess(): void
    {
        $this->client->request('GET', '/cms-api/v1/admin/assets', [], [], ['CONTENT_TYPE' => 'application/json']);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    protected function tearDown(): void
    {
        // Delete through the API so the stored files are removed too (DAMA
        // only rolls back the database).
        foreach ($this->createdAssetIds as $assetId) {
            $this->client->request('DELETE', "/cms-api/v1/admin/assets/{$assetId}", [], [], $this->adminHeaders());
        }

        $this->createdAssetIds = [];
        parent::tearDown();
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function upload(string $name, array $fields = []): array
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            $fields,
            ['file' => $this->tempUpload($name, 'first-bytes')],
            $this->adminHeaders()
        );

        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $asset = $this->asArray($this->decodeArray()['data']);
        $this->createdAssetIds[] = $this->asInt($asset['id']);

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    private function importBundle(string $zipPath): array
    {
        // UploadedFile in test mode moves the source, so import a copy.
        $copy = (string) tempnam(sys_get_temp_dir(), 'qa_bundle_copy');
        copy($zipPath, $copy);

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/import',
            [],
            ['file' => new UploadedFile($copy, 'qa-bundle.zip', 'application/zip', null, true)],
            $this->adminHeaders()
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->asArray($this->decodeArray()['data']);
    }

    /**
     * A v2.0 asset bundle with these entries (uuid, name, group names).
     *
     * @param list<array{uuid: string, name: string, groups: list<string>}> $entries
     */
    private function buildBundle(array $entries): string
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'qa_bundle');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        $manifestEntries = [];
        foreach ($entries as $entry) {
            $bundlePath = 'files/' . $entry['uuid'];
            $zip->addFromString($bundlePath, AssetFixtures::png('first-bytes'));
            $manifestEntries[] = [
                'uuid' => $entry['uuid'],
                'name' => $entry['name'],
                'alt' => null,
                'mime_type' => 'image/png',
                'extension' => 'png',
                'asset_type' => 'Asset',
                'groups' => $entry['groups'],
                'bundle_path' => $bundlePath,
            ];
        }
        $zip->addFromString('manifest.json', (string) json_encode([
            'bundle_type' => 'selfhelp/asset-bundle',
            'bundle_version' => '2.0',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'assets' => $manifestEntries,
        ]));
        $zip->close();

        return $zipPath;
    }

    /** A real file of the type of `$clientName`, made unique by `$salt`. */
    private function tempUpload(string $clientName, string $salt): UploadedFile
    {
        return AssetFixtures::upload($clientName, null, $salt);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $this->adminHeaders());

        return $this->asArray($this->decodeArray()['data']);
    }

    /**
     * @param list<int> $assetIds
     * @param list<int> $groupIds
     */
    private function bulkGroups(array $assetIds, array $groupIds, string $mode): void
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/bulk-groups',
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['asset_ids' => $assetIds, 'group_ids' => $groupIds, 'mode' => $mode])
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function deliveredBody(array $asset): string
    {
        $this->client->request('GET', $this->asString($asset['url']), [], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $asset
     * @return list<string>
     */
    private function groupNames(array $asset): array
    {
        $names = array_map(
            fn (mixed $group): string => $this->asString($this->asArray($group)['name']),
            $this->asList($asset['groups'])
        );
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulkMove(array $body): void
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets/bulk-folder',
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body)
        );
    }

    /**
     * Ids of the listed assets for these query parameters.
     *
     * @param array<string, string> $query
     * @return list<mixed>
     */
    private function listedIds(array $query): array
    {
        $this->client->request('GET', '/cms-api/v1/admin/assets', $query + ['pageSize' => '1000'], [], $this->adminHeaders());
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        return array_map(fn (mixed $row): mixed => $this->asArray($row)['id'], $this->asList($this->asArray($this->decodeArray()['data'])['assets']));
    }

    /**
     * @param array<string, mixed> $folder
     */
    private function saveFolder(array $folder): int
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/asset-folders',
            [],
            [],
            $this->adminHeaders() + ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($folder)
        );
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        foreach ($this->asList($this->decodeArray()['data']) as $row) {
            $row = $this->asArray($row);
            if ($row['name'] === $folder['name']) {
                return $this->asInt($row['id']);
            }
        }
        self::fail('Saved folder missing from the folder list');
    }

    /** Id of a custom `qa_` group, created on first use (the seed has only `subject`). */
    private function groupId(string $name): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $group = (new GroupFactory($em))->createGroup($name);

        return (int) $group->getId();
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()];
    }
}
