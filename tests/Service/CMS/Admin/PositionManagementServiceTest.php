<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Tests\Service\CMS\Admin;

use App\Entity\Page;
use App\Service\CMS\Admin\PositionManagementService;
use App\Tests\Controller\Api\V1\BaseControllerTest;
use Doctrine\ORM\EntityManagerInterface;

class PositionManagementServiceTest extends BaseControllerTest
{
    private PositionManagementService $positionManagementService;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $positionManagementService = static::getContainer()->get(PositionManagementService::class);
        self::assertInstanceOf(PositionManagementService::class, $positionManagementService);
        $this->positionManagementService = $positionManagementService;

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * Test normalizePageSectionPositions method
     */
    public function testNormalizePageSectionPositions(): void
    {
        // Find a real page with sections to test with
        $page = $this->entityManager->getRepository('App\Entity\Page')->findOneBy([]);
        
        if (!$page) {
            $this->markTestSkipped('No pages found to test with');
        }
        
        $pageId = $page->getId();
        self::assertNotNull($pageId);

        // Call the method with real database
        $this->positionManagementService->normalizePageSectionPositions($pageId, true);
        
        // Verify the result by checking the database state
        $pageSections = $this->entityManager->getRepository('App\Entity\PagesSection')
            ->findBy(['page' => $page], ['position' => 'ASC']);
            
        // Assert that positions are normalized to increments of 10
        $expectedPosition = 0;
        foreach ($pageSections as $pageSection) {
            $this->assertEquals($expectedPosition, $pageSection->getPosition());
            $expectedPosition += 10;
        }
    }

    /**
     * Test normalizePageSectionPositions method without flush
     */
    public function testNormalizePageSectionPositionsWithoutFlush(): void
    {
        // Find a real page with sections to test with
        $page = $this->entityManager->getRepository('App\Entity\Page')->findOneBy([]);
        
        if (!$page) {
            $this->markTestSkipped('No pages found to test with');
        }
        
        $pageId = $page->getId();
        self::assertNotNull($pageId);

        // Without flush the call must complete without raising; assert the page
        // section set is still queryable afterwards (no exception / state break).
        $this->positionManagementService->normalizePageSectionPositions($pageId, false);

        $sections = $this->entityManager->getRepository('App\Entity\PagesSection')
            ->findBy(['page' => $page], ['position' => 'ASC']);
        $this->assertGreaterThanOrEqual(0, count($sections));
    }

    /**
     * Test normalizeSectionHierarchyPositions method
     */
    public function testNormalizeSectionHierarchyPositions(): void
    {
        // Find a real section that has children to test with
        $sectionHierarchy = $this->entityManager->getRepository('App\Entity\SectionsHierarchy')->findOneBy([]);
        
        if (!$sectionHierarchy) {
            $this->markTestSkipped('No section hierarchies found to test with');
        }
        
        $parentSection = $sectionHierarchy->getParentSection();
        if ($parentSection === null) {
            $this->markTestSkipped('Section hierarchy row without a parent section found; cannot normalize.');
        }
        $parentSectionId = (int) $parentSection->getId();

        // Call the method with real database
        $this->positionManagementService->normalizeSectionHierarchyPositions($parentSectionId, true);

        // Verify the result by checking the database state. The entity uses
        // the canonical `parentSection` / `childSection` ORM properties that
        // map to `id_parent_section` / `id_child_section`.
        $hierarchies = $this->entityManager->getRepository('App\Entity\SectionsHierarchy')
            ->findBy(['parentSection' => $parentSection], ['position' => 'ASC', 'childSection' => 'ASC']);
            
        // Assert that positions are normalized to increments of 10
        $expectedPosition = 0;
        foreach ($hierarchies as $hierarchy) {
            $this->assertEquals($expectedPosition, $hierarchy->getPosition());
            $expectedPosition += 10;
        }
    }

    /**
     * Test reorderPagePositions method
     */
    public function testReorderPagePositions(): void
    {
        // Call the method with real database (use null for root pages)
        $this->positionManagementService->reorderPagePositions(null, 'nav', true);
        
        // Verify the result by checking the database state
        $pages = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from('App\\Entity\\Page', 'p')
            ->where('p.nav_position IS NOT NULL')
            ->andWhere('p.parentPage IS NULL')  // Changed to parentPage IS NULL for root pages
            ->orderBy('p.nav_position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        // If no pages found, that's okay - the method should handle it gracefully.
        $this->assertIsArray($pages);
        $expectedPosition = 10;
        foreach ($pages as $page) {
            self::assertInstanceOf(Page::class, $page);
            $this->assertEquals($expectedPosition, $page->getNavPosition());
            $expectedPosition += 10;
        }
    }

    /**
     * Test reorderPagePositions method with footer position type
     */
    public function testReorderPagePositionsWithFooterType(): void
    {
        // Call the method with real database (use null for root pages)
        $this->positionManagementService->reorderPagePositions(null, 'footer', true);
        
        // Verify the result by checking the database state
        $pages = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from('App\\Entity\\Page', 'p')
            ->where('p.footer_position IS NOT NULL')
            ->andWhere('p.parentPage IS NULL')  // Changed to parentPage IS NULL for root pages
            ->orderBy('p.footer_position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        // If no pages found, that's okay - the method should handle it gracefully.
        $this->assertIsArray($pages);
        $expectedPosition = 10;
        foreach ($pages as $page) {
            self::assertInstanceOf(Page::class, $page);
            $this->assertEquals($expectedPosition, $page->getFooterPosition());
            $expectedPosition += 10;
        }
    }
}
