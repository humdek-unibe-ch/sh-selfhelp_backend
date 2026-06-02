<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Controller\Api\V1;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class AdminAssetControllerTest extends BaseControllerTest
{
    /** @var list<int> */
    private array $createdAssetIds = [];

    /**
     * @group asset-management
     */
    public function testGetAllAssetsSuccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/assets',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        
        // Validate response structure with pagination
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('assets', $payload);
        $this->assertArrayHasKey('pagination', $payload);
        
        // Validate pagination structure
        $pagination = $this->asArray($payload['pagination']);
        $this->assertArrayHasKey('page', $pagination);
        $this->assertArrayHasKey('pageSize', $pagination);
        $this->assertArrayHasKey('total', $pagination);
        $this->assertArrayHasKey('totalPages', $pagination);
        $this->assertIsInt($pagination['page']);
        $this->assertIsInt($pagination['pageSize']);
        $this->assertIsInt($pagination['total']);
        
        // Validate assets array
        $assets = $this->asList($payload['assets']);
        
        if (!empty($assets)) {
            $asset = $this->asArray($assets[0]);
            $this->assertArrayHasKey('id', $asset);
            $this->assertArrayHasKey('asset_type', $asset);
            $this->assertArrayHasKey('folder', $asset);
            $this->assertArrayHasKey('file_name', $asset);
            $this->assertArrayHasKey('file_path', $asset);
            $this->assertArrayHasKey('url', $asset);
            $this->assertIsInt($asset['id']);
            $this->assertIsString($asset['asset_type']);
        }
    }

    /**
     * @group asset-management
     */
    public function testGetAllAssetsWithPaginationAndSearch(): void
    {
        // Test with pagination parameters
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/assets?page=1&pageSize=5&search=test&folder=test',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $payload = $this->asArray($data['data']);
        
        // Validate pagination reflects requested parameters
        $pagination = $this->asArray($payload['pagination']);
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(5, $pagination['pageSize']);
        
        // Validate assets array size doesn't exceed pageSize
        $this->assertLessThanOrEqual(5, count($this->asList($payload['assets'])));
    }

    /**
     * @group asset-management
     */
    public function testCreateAssetSuccess(): void
    {
        // Create a temporary test file
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_image');
        self::assertNotFalse($testFilePath, 'Failed to create temp file');
        file_put_contents($testFilePath, 'test image content');
        
        $uploadedFile = new UploadedFile(
            $testFilePath,
            'test-image.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'test-upload.jpg'
            ],
            ['file' => $uploadedFile],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $data = $this->decodeArray();
        
        // Validate response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('file_name', $payload);
        $this->assertArrayHasKey('folder', $payload);
        $this->assertArrayHasKey('url', $payload);
        
        // Validate data
        $this->assertSame('test-upload.jpg', $payload['file_name']);
        $this->assertSame('test', $payload['folder']);
        $this->assertStringContainsString('test-upload.jpg', $this->asString($payload['file_path']));
        
        // Store for cleanup
        $this->createdAssetIds[] = $this->asInt($payload['id']);
    }

    /**
     * @group asset-management
     */
    public function testCreateAssetWithOverwrite(): void
    {
        // Create first asset
        $testFilePath1 = tempnam(sys_get_temp_dir(), 'test_image1');
        self::assertNotFalse($testFilePath1, 'Failed to create temp file');
        file_put_contents($testFilePath1, 'test image content 1');
        
        $uploadedFile1 = new UploadedFile(
            $testFilePath1,
            'overwrite-test.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'overwrite-test.jpg'
            ],
            ['file' => $uploadedFile1],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $firstResponse = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $firstResponse->getStatusCode());
        
        $firstData = $this->decodeArray();
        $this->createdAssetIds[] = $this->asInt($this->jsonGet($firstData, 'data', 'id'));

        // Try to create second asset with same name (should fail without overwrite)
        $testFilePath2 = tempnam(sys_get_temp_dir(), 'test_image2');
        self::assertNotFalse($testFilePath2, 'Failed to create temp file');
        file_put_contents($testFilePath2, 'test image content 2');
        
        $uploadedFile2 = new UploadedFile(
            $testFilePath2,
            'overwrite-test.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'overwrite-test.jpg'
            ],
            ['file' => $uploadedFile2],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $conflictResponse = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CONFLICT, $conflictResponse->getStatusCode());

        // Now try with overwrite flag
        $testFilePath3 = tempnam(sys_get_temp_dir(), 'test_image3');
        self::assertNotFalse($testFilePath3, 'Failed to create temp file');
        file_put_contents($testFilePath3, 'test image content 3');
        
        $uploadedFile3 = new UploadedFile(
            $testFilePath3,
            'overwrite-test.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'overwrite-test.jpg',
                'overwrite' => '1'
            ],
            ['file' => $uploadedFile3],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $overwriteResponse = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $overwriteResponse->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testCreateAssetInvalidFileType(): void
    {
        // Create a temporary test file with invalid extension
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_invalid');
        self::assertNotFalse($testFilePath, 'Failed to create temp file');
        file_put_contents($testFilePath, 'invalid file content');
        
        $uploadedFile = new UploadedFile(
            $testFilePath,
            'test-invalid.exe',
            'application/octet-stream',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'test-invalid.exe'
            ],
            ['file' => $uploadedFile],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testCreateAssetMissingFile(): void
    {
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test'
            ],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testGetAssetByIdSuccess(): void
    {
        // First create an asset
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_get_by_id');
        self::assertNotFalse($testFilePath, 'Failed to create temp file');
        file_put_contents($testFilePath, 'test content for get by id');
        
        $uploadedFile = new UploadedFile(
            $testFilePath,
            'get-by-id-test.txt',
            'text/plain',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'get-by-id-test.txt'
            ],
            ['file' => $uploadedFile],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $createResponse = $this->client->getResponse();
        $createData = $this->decodeArray();
        $assetId = $this->asInt($this->jsonGet($createData, 'data', 'id'));
        $this->createdAssetIds[] = $assetId;

        // Now get the asset by ID
        $this->client->request(
            'GET',
            "/cms-api/v1/admin/assets/{$assetId}",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        
        // Validate response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertSame($assetId, $payload['id']);
        $this->assertSame('get-by-id-test.txt', $payload['file_name']);
        $this->assertSame('test', $payload['folder']);
    }

    /**
     * @group asset-management
     */
    public function testGetAssetByIdNotFound(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/assets/99999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testDeleteAssetSuccess(): void
    {
        // First create an asset
        $testFilePath = tempnam(sys_get_temp_dir(), 'test_delete');
        self::assertNotFalse($testFilePath, 'Failed to create temp file');
        file_put_contents($testFilePath, 'test content for delete');
        
        $uploadedFile = new UploadedFile(
            $testFilePath,
            'delete-test.txt',
            'text/plain',
            null,
            true
        );

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test',
                'file_name' => 'delete-test.txt'
            ],
            ['file' => $uploadedFile],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $createResponse = $this->client->getResponse();
        $createData = $this->decodeArray();
        $assetId = $this->asInt($this->jsonGet($createData, 'data', 'id'));

        // Now delete the asset
        $this->client->request(
            'DELETE',
            "/cms-api/v1/admin/assets/{$assetId}",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeArray();
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertTrue($payload['deleted']);

        // Verify asset is deleted
        $this->client->request(
            'GET',
            "/cms-api/v1/admin/assets/{$assetId}",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $getResponse = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $getResponse->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testDeleteAssetNotFound(): void
    {
        $this->client->request(
            'DELETE',
            '/cms-api/v1/admin/assets/99999',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testUnauthorizedAccess(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/assets',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * @group asset-management
     */
    public function testCreateMultipleAssetsSuccess(): void
    {
        // Create multiple temporary test files
        $testFiles = [];
        for ($i = 1; $i <= 3; $i++) {
            $testFilePath = tempnam(sys_get_temp_dir(), "test_image_$i");
            self::assertNotFalse($testFilePath, 'Failed to create temp file');
            file_put_contents($testFilePath, "test image content $i");
            
            $testFiles[] = new UploadedFile(
                $testFilePath,
                "test-multi-$i.jpg",
                'image/jpeg',
                null,
                true
            );
        }

        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            [
                'folder' => 'test-multi',
                'file_names' => ['custom-1.jpg', 'custom-2.jpg', 'custom-3.jpg']
            ],
            ['files' => $testFiles],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken()
            ]
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $data = $this->decodeArray();
        
        // Validate multiple upload response structure
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('data', $data);
        $payload = $this->asArray($data['data']);
        $this->assertArrayHasKey('uploaded', $payload);
        $this->assertArrayHasKey('errors', $payload);
        $this->assertArrayHasKey('total_files', $payload);
        $this->assertArrayHasKey('successful_uploads', $payload);
        $this->assertArrayHasKey('failed_uploads', $payload);
        
        // Validate upload statistics
        $this->assertSame(3, $payload['total_files']);
        $this->assertSame(3, $payload['successful_uploads']);
        $this->assertSame(0, $payload['failed_uploads']);
        $this->assertEmpty($payload['errors']);
        
        // Validate uploaded assets
        $uploaded = $this->asList($payload['uploaded']);
        $this->assertCount(3, $uploaded);
        
        foreach ($uploaded as $index => $assetRaw) {
            $asset = $this->asArray($assetRaw);
            $this->assertArrayHasKey('id', $asset);
            $this->assertArrayHasKey('file_name', $asset);
            $this->assertArrayHasKey('folder', $asset);
            $this->assertSame('test-multi', $asset['folder']);
            $this->assertSame("custom-" . ($index + 1) . ".jpg", $asset['file_name']);
            
            // Store for cleanup
            $this->createdAssetIds[] = $this->asInt($asset['id']);
        }
    }

    protected function tearDown(): void
    {
        // Clean up created assets
        foreach ($this->createdAssetIds as $assetId) {
            $this->client->request(
                'DELETE',
                "/cms-api/v1/admin/assets/{$assetId}",
                [],
                [],
                [
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getAdminAccessToken(),
                    'CONTENT_TYPE' => 'application/json'
                ]
            );
        }

        $this->createdAssetIds = [];
        parent::tearDown();
    }
} 