<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AssetStorageService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\QaWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The file of an asset and its database row change together or not at all.
 *
 * Replacing the bytes goes through a staged file and one rename, after the row
 * is written and just before the commit; a failed edit therefore leaves the old
 * bytes in place and no temporary file behind. Deleting removes the file only
 * after the row is gone.
 */
final class AssetFileReplaceTest extends QaWebTestCase
{
    /** @var list<string> */
    private array $uuids = [];

    /** @var list<string> hidden files that were there before the test (a crashed earlier run may leave some) */
    private array $hiddenBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hiddenBefore = $this->temporaryFiles();
    }

    protected function tearDown(): void
    {
        $storage = $this->service(AssetStorageService::class);
        foreach ($this->uuids as $uuid) {
            @unlink($storage->absolutePath($uuid));
        }
        parent::tearDown();
    }

    public function testReplacingTheFileSwapsTheBytesUnderTheSameUuidAndLeavesNothingTemporary(): void
    {
        $asset = $this->upload('old-bytes', 'qa-replace.png');
        $uuid = $this->asString($asset['uuid']);

        $this->edit($this->asInt($asset['id']), ['alt' => 'qa replaced'], $this->file('new-bytes-that-are-longer', 'qa-replace.png'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $replaced = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));

        self::assertSame($uuid, $replaced['uuid'], 'The uuid, and so every URL in use, stays');
        self::assertSame(strlen(AssetFixtures::png('new-bytes-that-are-longer')), $replaced['size'], 'The row describes the new file');
        self::assertSame(AssetFixtures::png('new-bytes-that-are-longer'), $this->delivered($uuid));
        self::assertSame($this->hiddenBefore, $this->temporaryFiles(), 'No staged or backup file is left');
    }

    public function testAFailedEditKeepsTheOldBytesAndLeavesNothingTemporary(): void
    {
        $asset = $this->upload('old-bytes', 'qa-keep.png');
        $uuid = $this->asString($asset['uuid']);

        // The role does not exist, which is found only after the new file is staged.
        $this->edit($this->asInt($asset['id']), ['role_ids' => '2147483600'], $this->file('new-bytes', 'qa-keep.png'));

        self::assertSame(400, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(AssetFixtures::png('old-bytes'), $this->delivered($uuid), 'The edit failed, so the file is as it was');
        self::assertSame($this->hiddenBefore, $this->temporaryFiles(), 'The staged file is removed again');

        $row = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']), null, $this->loginAsQaAdmin()));
        self::assertSame(strlen(AssetFixtures::png('old-bytes')), $row['size'], 'The row still describes the old file');
    }

    public function testDeletingRemovesTheRowAndTheFile(): void
    {
        $asset = $this->upload('bytes', 'qa-delete-file.png');
        $uuid = $this->asString($asset['uuid']);
        $storage = $this->service(AssetStorageService::class);
        self::assertNotNull($storage->resolveForDelivery($uuid));

        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $this->asInt($asset['id']), [], [], $this->admin());
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        self::assertNull($this->service(AssetStorageService::class)->resolveForDelivery($uuid), 'The file goes with its row');
        self::assertSame($this->hiddenBefore, $this->temporaryFiles());
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin()];
    }

    /** A real PNG made unique by `$salt` (an upload must fit its extension). */
    private function file(string $salt, string $name): UploadedFile
    {
        return AssetFixtures::upload($name, AssetFixtures::png($salt));
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $bytes, string $name): array
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets', [], ['file' => $this->file($bytes, $name)], $this->admin());
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $asset = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()), 201);
        $this->uuids[] = $this->asString($asset['uuid']);

        return $asset;
    }

    /**
     * @param array<string, string> $fields
     */
    private function edit(int $id, array $fields, UploadedFile $file): void
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, $fields, ['file' => $file], $this->admin());
    }

    private function delivered(string $uuid): string
    {
        $this->client->request('GET', '/cms-api/v1/assets/' . $uuid, [], [], $this->admin());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Hidden files in the asset storage: staged (`.part`) and backup (`.bak`)
     * files must never outlive a request.
     *
     * @return list<string>
     */
    private function temporaryFiles(): array
    {
        $root = $this->service(AssetStorageService::class)->storageRoot();

        return array_values(array_filter(
            array_diff(scandir($root) ?: [], ['.', '..']),
            static fn (string $name): bool => str_starts_with($name, '.')
        ));
    }
}
