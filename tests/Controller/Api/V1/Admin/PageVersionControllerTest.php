<?php

namespace App\Tests\Controller\Api\V1\Admin;

use App\Repository\PageRepository;
use App\Repository\PageVersionRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * PageVersionController Test
 * 
 * API integration tests for page versioning endpoints
 * 
 * @group api
 * @group versioning
 */
class PageVersionControllerTest extends WebTestCase
{
    private $client = null;
    private ?PageRepository $pageRepository = null;
    private ?PageVersionRepository $pageVersionRepository = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        
        $this->pageRepository = $container->get(PageRepository::class);
        $this->pageVersionRepository = $container->get(PageVersionRepository::class);
    }

    /**
     * Helper method to authenticate as admin
     */
    private function authenticateAsAdmin(): void
    {
        // Add authentication logic here based on your auth system
        // Example: Set JWT token or session
    }

    /**
     * Test publish page endpoint
     */
    public function testPublishPage(): void
    {
        $this->authenticateAsAdmin();

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();

        // Make API request to publish
        $this->client->request(
            'POST',
            "/cms-api/v1/admin/pages/{$pageId}/versions/publish",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'version_name' => 'API Test Version',
                'metadata' => ['test' => true]
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('version_id', $data);
        $this->assertArrayHasKey('version_number', $data);
        $this->assertArrayHasKey('published_at', $data);

        // Cleanup
        if (isset($data['version_id'])) {
            $version = $this->pageVersionRepository->find($data['version_id']);
            if ($version) {
                $page->setPublishedVersionId(null);
                $entityManager = static::getContainer()->get('doctrine')->getManager();
                $entityManager->persist($page);
                $entityManager->remove($version);
                $entityManager->flush();
            }
        }
    }

    /**
     * Test list versions endpoint
     */
    public function testListVersions(): void
    {
        $this->authenticateAsAdmin();

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();

        // Make API request
        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions");

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('versions', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertIsArray($data['versions']);
    }

    /**
     * Test get specific version endpoint
     */
    public function testGetVersion(): void
    {
        $this->authenticateAsAdmin();

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();

        // Create a test version first
        $this->client->request(
            'POST',
            "/cms-api/v1/admin/pages/{$pageId}/versions/publish",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['version_name' => 'Test Get Version'])
        );

        $publishResponse = json_decode($this->client->getResponse()->getContent(), true);
        $versionId = $publishResponse['version_id'];

        // Get the version
        $this->client->request('GET', "/cms-api/v1/admin/pages/{$pageId}/versions/{$versionId}");

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('version_number', $data);
        $this->assertArrayHasKey('version_name', $data);
        $this->assertEquals($versionId, $data['id']);

        // Cleanup
        $version = $this->pageVersionRepository->find($versionId);
        if ($version) {
            $page->setPublishedVersionId(null);
            $entityManager = static::getContainer()->get('doctrine')->getManager();
            $entityManager->persist($page);
            $entityManager->remove($version);
            $entityManager->flush();
        }
    }

    /**
     * Test unpublish endpoint
     */
    public function testUnpublishPage(): void
    {
        $this->authenticateAsAdmin();

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();

        // First publish a version
        $this->client->request(
            'POST',
            "/cms-api/v1/admin/pages/{$pageId}/versions/publish",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['version_name' => 'Test Unpublish'])
        );

        $publishResponse = json_decode($this->client->getResponse()->getContent(), true);
        $versionId = $publishResponse['version_id'];

        // Now unpublish
        $this->client->request('POST', "/cms-api/v1/admin/pages/{$pageId}/versions/unpublish");

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('message', $data);

        // Cleanup
        $version = $this->pageVersionRepository->find($versionId);
        if ($version) {
            $entityManager = static::getContainer()->get('doctrine')->getManager();
            $entityManager->remove($version);
            $entityManager->flush();
        }
    }

    /**
     * Test compare versions endpoint
     */
    public function testCompareVersions(): void
    {
        $this->authenticateAsAdmin();

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();

        // Create two versions
        $this->client->request(
            'POST',
            "/cms-api/v1/admin/pages/{$pageId}/versions/publish",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['version_name' => 'Version 1 for Compare'])
        );
        $version1Id = json_decode($this->client->getResponse()->getContent(), true)['version_id'];

        $this->client->request(
            'POST',
            "/cms-api/v1/admin/pages/{$pageId}/versions/publish",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['version_name' => 'Version 2 for Compare'])
        );
        $version2Id = json_decode($this->client->getResponse()->getContent(), true)['version_id'];

        // Compare versions
        $this->client->request(
            'GET',
            "/cms-api/v1/admin/pages/{$pageId}/versions/compare/{$version1Id}/{$version2Id}?format=summary"
        );

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('version1', $data);
        $this->assertArrayHasKey('version2', $data);
        $this->assertArrayHasKey('diff', $data);
        $this->assertArrayHasKey('format', $data);

        // Cleanup
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $version1 = $this->pageVersionRepository->find($version1Id);
        $version2 = $this->pageVersionRepository->find($version2Id);
        
        $page->setPublishedVersionId(null);
        $entityManager->persist($page);
        
        if ($version1) $entityManager->remove($version1);
        if ($version2) $entityManager->remove($version2);
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->client = null;
        $this->pageRepository = null;
        $this->pageVersionRepository = null;
    }
}

