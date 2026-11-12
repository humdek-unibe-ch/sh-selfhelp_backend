<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\CMS\Admin\AssetStorageService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Small helpers to drive the asset and asset-folder API from a `QaWebTestCase`.
 *
 * Stored files are outside the database transaction the tests roll back, so
 * every uploaded uuid is remembered and `removeUploadedAssetFiles()` (call it
 * from `tearDown()`) deletes the files again.
 */
trait InteractsWithAssetApi
{
    /** @var list<string> */
    private array $uploadedAssetUuids = [];

    protected function removeUploadedAssetFiles(): void
    {
        $storage = $this->service(AssetStorageService::class);
        foreach ($this->uploadedAssetUuids as $uuid) {
            @unlink($storage->absolutePath($uuid));
        }
        $this->uploadedAssetUuids = [];
    }

    /**
     * @return array<string, string>
     */
    protected function assetBearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * Upload one file and return the decoded envelope (its status is not asserted).
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    protected function uploadRaw(string $token, UploadedFile $file, array $fields = []): array
    {
        $this->client->request('POST', '/cms-api/v1/admin/assets', $fields, ['file' => $file], $this->assetBearer($token));
        $envelope = $this->decode($this->client->getResponse());

        $data = $envelope['data'] ?? null;
        if (is_array($data) && is_string($data['uuid'] ?? null)) {
            $this->uploadedAssetUuids[] = $data['uuid'];
        }

        return $envelope;
    }

    /**
     * Upload one file, assert 201 and return the asset.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    protected function uploadAsset(string $token, UploadedFile $file, array $fields = []): array
    {
        return $this->assertEnvelopeSuccess($this->uploadRaw($token, $file, $fields), 201);
    }

    /**
     * Create a folder, assert 200 and return its id.
     */
    protected function createFolder(string $token, string $name, ?int $parentId = null): int
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => $name, 'parent_id' => $parentId], $token);
        foreach ($this->asList($envelope['data'] ?? null) as $row) {
            $row = $this->asArray($row);
            if ($row['name'] === $name && $row['parent_id'] === $parentId) {
                return $this->asInt($row['id']);
            }
        }
        self::fail('The folder was not created: ' . json_encode($envelope));
    }

    /**
     * The folder list as id => row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function folderRows(string $token): array
    {
        $rows = [];
        foreach ($this->asList($this->jsonRequest('GET', '/cms-api/v1/admin/asset-folders', null, $token)['data'] ?? null) as $row) {
            $row = $this->asArray($row);
            $rows[$this->asInt($row['id'])] = $row;
        }

        return $rows;
    }

    /**
     * One asset as the admin API shows it to this caller.
     *
     * @return array<string, mixed>
     */
    protected function assetRow(string $token, int $id): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/assets/' . $id, null, $token));
    }

    /**
     * @param array<string, mixed> $asset
     * @return list<string> group names of an asset row, sorted
     */
    protected function assetGroupNames(array $asset): array
    {
        $names = array_map(fn (mixed $group): string => $this->asString($this->asArray($group)['name']), $this->asList($asset['groups']));
        sort($names);

        return $names;
    }
}
