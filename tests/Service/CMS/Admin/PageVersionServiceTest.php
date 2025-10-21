<?php

namespace App\Tests\Service\CMS\Admin;

use App\Entity\Page;
use App\Entity\PageVersion;
use App\Repository\PageRepository;
use App\Repository\PageVersionRepository;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\TransactionService;
use App\Service\Auth\UserContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PageVersionService Test
 * 
 * Integration tests for page versioning functionality
 * 
 * @group integration
 * @group versioning
 */
class PageVersionServiceTest extends KernelTestCase
{
    private ?PageVersionService $pageVersionService = null;
    private ?PageRepository $pageRepository = null;
    private ?PageVersionRepository $pageVersionRepository = null;
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $container = static::getContainer();
        $this->pageVersionService = $container->get(PageVersionService::class);
        $this->pageRepository = $container->get(PageRepository::class);
        $this->pageVersionRepository = $container->get(PageVersionRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    /**
     * Test creating a new version from current page state
     */
    public function testCreateVersion(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create a version
        $version = $this->pageVersionService->createVersion(
            $pageId,
            'Test Version',
            ['description' => 'Test version created by unit test']
        );

        // Assertions
        $this->assertInstanceOf(PageVersion::class, $version);
        $this->assertNotNull($version->getId());
        $this->assertEquals('Test Version', $version->getVersionName());
        $this->assertNotNull($version->getPageJson());
        $this->assertIsArray($version->getPageJson());
        $this->assertGreaterThan(0, $version->getVersionNumber());

        // Cleanup
        $this->entityManager->remove($version);
        $this->entityManager->flush();
    }

    /**
     * Test publishing a version
     */
    public function testPublishVersion(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create a version
        $version = $this->pageVersionService->createVersion($pageId, 'Test Publish Version');
        
        // Publish it
        $publishedVersion = $this->pageVersionService->publishVersion($pageId, $version->getId());

        // Assertions
        $this->assertTrue($publishedVersion->isPublished());
        $this->assertNotNull($publishedVersion->getPublishedAt());
        
        // Verify page has published_version_id set
        $this->entityManager->refresh($page);
        $this->assertEquals($version->getId(), $page->getPublishedVersionId());

        // Cleanup
        $page->setPublishedVersionId(null);
        $this->entityManager->persist($page);
        $this->entityManager->remove($version);
        $this->entityManager->flush();
    }

    /**
     * Test unpublishing a page
     */
    public function testUnpublishPage(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create and publish a version
        $version = $this->pageVersionService->createAndPublishVersion($pageId, 'Test Unpublish');
        
        // Verify it's published
        $this->entityManager->refresh($page);
        $this->assertNotNull($page->getPublishedVersionId());

        // Unpublish
        $this->pageVersionService->unpublishPage($pageId);

        // Verify it's unpublished
        $this->entityManager->refresh($page);
        $this->assertNull($page->getPublishedVersionId());

        // Cleanup
        $this->entityManager->remove($version);
        $this->entityManager->flush();
    }

    /**
     * Test getting version history
     */
    public function testGetVersionHistory(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create multiple versions
        $versions = [];
        for ($i = 1; $i <= 3; $i++) {
            $versions[] = $this->pageVersionService->createVersion($pageId, "Test Version {$i}");
        }

        // Get version history
        $history = $this->pageVersionService->getVersionHistory($pageId, 10, 0);

        // Assertions
        $this->assertIsArray($history);
        $this->assertArrayHasKey('versions', $history);
        $this->assertArrayHasKey('total_count', $history);
        $this->assertGreaterThanOrEqual(3, $history['total_count']);
        $this->assertGreaterThanOrEqual(3, count($history['versions']));

        // Cleanup
        foreach ($versions as $version) {
            $this->entityManager->remove($version);
        }
        $this->entityManager->flush();
    }

    /**
     * Test comparing two versions
     */
    public function testCompareVersions(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create two versions
        $version1 = $this->pageVersionService->createVersion($pageId, 'Version 1');
        $version2 = $this->pageVersionService->createVersion($pageId, 'Version 2');

        // Compare versions
        $comparison = $this->pageVersionService->compareVersions(
            $version1->getId(),
            $version2->getId(),
            'summary'
        );

        // Assertions
        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('version1', $comparison);
        $this->assertArrayHasKey('version2', $comparison);
        $this->assertArrayHasKey('diff', $comparison);
        $this->assertEquals('summary', $comparison['format']);

        // Cleanup
        $this->entityManager->remove($version1);
        $this->entityManager->remove($version2);
        $this->entityManager->flush();
    }

    /**
     * Test retention policy
     */
    public function testApplyRetentionPolicy(): void
    {
        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create multiple versions (more than keep count)
        $versions = [];
        for ($i = 1; $i <= 5; $i++) {
            $versions[] = $this->pageVersionService->createVersion($pageId, "Retention Test Version {$i}");
        }

        // Apply retention policy (keep only 2 most recent)
        $deletedCount = $this->pageVersionService->applyRetentionPolicy($pageId, 2);

        // Assertions
        $this->assertGreaterThanOrEqual(3, $deletedCount); // Should delete at least 3 old versions

        // Verify only 2 versions remain from our test set
        $remainingVersions = $this->pageVersionRepository->findByPage($pageId);
        
        // Note: There might be other versions from previous tests, so we check that
        // at least 2 of our test versions remain
        $testVersionCount = 0;
        foreach ($remainingVersions as $version) {
            if (strpos($version->getVersionName(), 'Retention Test Version') !== false) {
                $testVersionCount++;
            }
        }
        
        $this->assertLessThanOrEqual(2, $testVersionCount);

        // Cleanup remaining test versions
        foreach ($remainingVersions as $version) {
            if (strpos($version->getVersionName() ?? '', 'Retention Test Version') !== false) {
                try {
                    $this->pageVersionService->deleteVersion($version->getId());
                } catch (\Exception $e) {
                    // Ignore errors during cleanup
                }
            }
        }
    }

    /**
     * Test that published version cannot be deleted
     */
    public function testCannotDeletePublishedVersion(): void
    {
        $this->expectException(\App\Exception\ServiceException::class);
        $this->expectExceptionMessage('Cannot delete the currently published version');

        // Get a test page
        $page = $this->pageRepository->findOneBy(['keyword' => 'home']);
        $this->assertNotNull($page, 'Test page not found');

        $pageId = $page->getId();
        
        // Create and publish a version
        $version = $this->pageVersionService->createAndPublishVersion($pageId, 'Test Delete Published');
        
        try {
            // Attempt to delete the published version (should fail)
            $this->pageVersionService->deleteVersion($version->getId());
        } finally {
            // Cleanup
            $this->pageVersionService->unpublishPage($pageId);
            $this->entityManager->remove($version);
            $this->entityManager->flush();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        $this->pageVersionService = null;
        $this->pageRepository = null;
        $this->pageVersionRepository = null;
        $this->entityManager = null;
    }
}

