<?php

namespace App\Tests\Api\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test suite for Draft vs Published Page Comparison API
 *
 * Tests the new endpoint: GET /admin/pages/{page_id}/versions/compare-draft/{version_id}
 *
 * Prerequisites:
 * - Test database must be seeded with pages and versions
 * - User must have admin.page_version.compare permission
 * - Valid JWT token required
 */
class PageVersionDraftComparisonTest extends WebTestCase
{
    private $client;
    private $jwtToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Get JWT token for admin user
        // Note: Replace with your actual authentication method
        $this->client->request('POST', '/cms-api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->jwtToken = $data['data']['token'] ?? null;

        $this->assertNotNull($this->jwtToken, 'Failed to obtain JWT token');
    }

    /**
     * Test basic draft comparison with side_by_side format
     */
    public function testCompareDraftWithPublishedVersion_SideBySide(): void
    {
        // Arrange: Assume page ID 1 has a published version with ID 1
        $pageId = 1;
        $versionId = 1;

        // Act
        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        // Assert
        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);

        $comparisonData = $data['data'];
        $this->assertArrayHasKey('draft', $comparisonData);
        $this->assertArrayHasKey('published_version', $comparisonData);
        $this->assertArrayHasKey('diff', $comparisonData);
        $this->assertArrayHasKey('format', $comparisonData);
        $this->assertEquals('side_by_side', $comparisonData['format']);

        // Verify draft structure
        $this->assertArrayHasKey('id_pages', $comparisonData['draft']);
        $this->assertArrayHasKey('keyword', $comparisonData['draft']);
        $this->assertArrayHasKey('updated_at', $comparisonData['draft']);

        // Verify version structure
        $this->assertArrayHasKey('id', $comparisonData['published_version']);
        $this->assertArrayHasKey('version_number', $comparisonData['published_version']);
        $this->assertEquals($versionId, $comparisonData['published_version']['id']);
    }

    /**
     * Test draft comparison with unified format
     */
    public function testCompareDraftWithPublishedVersion_Unified(): void
    {
        $pageId = 1;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}?format=unified", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('unified', $data['data']['format']);
    }

    /**
     * Test draft comparison with JSON patch format
     */
    public function testCompareDraftWithPublishedVersion_JsonPatch(): void
    {
        $pageId = 1;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}?format=json_patch", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('json_patch', $data['data']['format']);
        $this->assertIsArray($data['data']['diff']);
    }

    /**
     * Test draft comparison with summary format
     */
    public function testCompareDraftWithPublishedVersion_Summary(): void
    {
        $pageId = 1;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}?format=summary", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('summary', $data['data']['format']);
    }

    /**
     * Test with invalid format parameter
     */
    public function testCompareDraftWithPublishedVersion_InvalidFormat(): void
    {
        $pageId = 1;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}?format=invalid_format", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid format', $data['message']);
    }

    /**
     * Test with non-existent page
     */
    public function testCompareDraftWithPublishedVersion_NonExistentPage(): void
    {
        $nonExistentPageId = 999999;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$nonExistentPageId}/versions/compare-draft/{$versionId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * Test with non-existent version
     */
    public function testCompareDraftWithPublishedVersion_NonExistentVersion(): void
    {
        $pageId = 1;
        $nonExistentVersionId = 999999;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$nonExistentVersionId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * Test with version that belongs to different page
     */
    public function testCompareDraftWithPublishedVersion_VersionFromDifferentPage(): void
    {
        // Assuming page 1 exists and page 2 exists with version 2
        $pageId = 1;
        $versionFromOtherPage = 2; // This version belongs to page 2, not page 1

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionFromOtherPage}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtToken,
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('does not belong to page', $data['message']);
    }

    /**
     * Test without authentication
     */
    public function testCompareDraftWithPublishedVersion_Unauthenticated(): void
    {
        $pageId = 1;
        $versionId = 1;

        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Test with user lacking required permission
     */
    public function testCompareDraftWithPublishedVersion_InsufficientPermissions(): void
    {
        // Login as a user without admin.page_version.compare permission
        $this->client->request('POST', '/cms-api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com', // Regular user without version compare permission
            'password' => 'user123'
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $userToken = $data['data']['token'] ?? null;

        if ($userToken) {
            $pageId = 1;
            $versionId = 1;

            $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/compare-draft/{$versionId}", [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $userToken,
                'CONTENT_TYPE' => 'application/json',
            ]);

            $response = $this->client->getResponse();
            $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        }
    }
}
