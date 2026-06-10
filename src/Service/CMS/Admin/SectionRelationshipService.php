<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Entity\Section;
use App\Entity\PagesSection;
use App\Entity\SectionsHierarchy;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\Traits\RelationshipManagerTrait;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Service\Core\UserContextAwareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling section relationship operations
 */
class SectionRelationshipService extends BaseService
{
    use RelationshipManagerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PositionManagementService $positionManagementService,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly UserContextAwareService $userContextAwareService
    ) {
    }

    /**
     * Add one or more sections to a page in a single atomic operation.
     *
     * The whole batch runs inside one transaction: each section's old parent
     * relationship is removed and the new page-section relationship is
     * created/updated, then a single flush + normalize is performed at the end.
     * This avoids the N-transaction, N-flush, N-normalize overhead of calling
     * the single-section flow in a loop from the controller.
     *
     * @param int   $pageId   The ID of the page to attach the sections to
     * @param list<array<string, mixed>> $sections Batch of section payloads. Each item must contain
     *                        `sectionId` and may include `position` and
     *                        `oldParentSectionId`.
     * @return array<int, array{id: int, position: int|null, sectionId: int}>
     *         Result for each input section, in the same order.
     * @throws ServiceException If the page is missing, access is denied, or
     *                          any section in the batch is not found.
     */
    public function addSectionToPage(int $pageId, array $sections): array
    {
        if ($sections === []) {
            return [];
        }

        $parentPage = $this->pageRepository->find($pageId);
        if (!$parentPage) {
            $this->throwNotFound('Page not found');
        }

        $this->userContextAwareService->checkAdminAccessById($pageId, 'update');

        $this->entityManager->beginTransaction();
        try {
            $results = [];
            $sectionRepository = $this->entityManager->getRepository(Section::class);

            foreach ($sections as $section) {
                $sectionId = $this->asInt($section['sectionId'] ?? null);
                $position = $this->asIntOrNull($section['position'] ?? null);
                $oldParentSectionId = $this->asIntOrNull($section['oldParentSectionId'] ?? null);

                $childSection = $sectionRepository->find($sectionId);
                if (!$childSection) {
                    $this->throwNotFound("Section {$sectionId} not found");
                }

                $this->removeOldParentRelationships(
                    null,
                    $oldParentSectionId,
                    $childSection,
                    $this->entityManager
                );

                $pageSection = $this->createOrUpdatePageSectionRelationship(
                    $parentPage,
                    $childSection,
                    $position,
                    $this->entityManager
                );

                $results[] = [
                    'id'        => (int) $pageSection->getSection()?->getId(),
                    'position'  => $pageSection->getPosition(),
                    'sectionId' => $sectionId,
                ];
            }

            // Single flush + single normalize for the whole batch
            $this->entityManager->flush();
            $this->positionManagementService->normalizePageSectionPositions($pageId);

            // Page-level audit entry: a single 'pages' transaction summarising
            // the batch so the page's audit trail captures "section attached"
            // operations (otherwise only section deletions were ever logged for
            // this service).
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                (object) [
                    'id' => $parentPage->getId(),
                    'keyword' => $parentPage->getKeyword(),
                    'url' => $parentPage->getUrl(),
                    'attached_sections' => array_column($results, 'sectionId'),
                ],
                "Attached " . count($results) . " section(s) to page '{$parentPage->getKeyword()}'"
            );

            // Cache invalidation: page + every touched section + lists
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
            foreach ($results as $row) {
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, $row['sectionId']);
            }
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();

            $this->entityManager->commit();

            return $results;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e instanceof ServiceException
                ? $e
                : new ServiceException(
                    'Failed to add sections to page: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    ['previous' => $e]
                );
        }
    }

    /**
     * Add one or more child sections to a parent section in a single atomic operation.
     *
     * Validates page access and parent-section membership once, then iterates the
     * batch inside a single transaction: each child's old parent relationships are
     * removed (and flushed before creating the new hierarchy row to avoid
     * identity-map conflicts on `(parentSection, childSection)`), the new hierarchy
     * row is created/updated, and a single normalize is performed at the end.
     *
     * @param int   $pageId          The page the parent section belongs to
     * @param int   $parentSectionId The section to nest the child sections under
     * @param list<array<string, mixed>> $sections        Batch of section payloads. Each item must
     *                               contain `sectionId` (or legacy `childSectionId`)
     *                               and may include `position`, `oldParentPageId`,
     *                               and `oldParentSectionId`.
     * @return array<int, array{id: int, position: int|null, sectionId: int}>
     *         Result for each input section, in the same order.
     * @throws ServiceException If access is denied, the parent section is missing,
     *                          or any child section in the batch is not found.
     */
    public function addSectionToSection(int $pageId, int $parentSectionId, array $sections): array
    {
        if ($sections === []) {
            return [];
        }

        $this->userContextAwareService->checkAdminAccessById($pageId, 'update');
        $this->checkSectionInPage($pageId, $parentSectionId);

        $this->entityManager->beginTransaction();
        try {
            $parentSection = $this->sectionRepository->find($parentSectionId);
            if (!$parentSection) {
                $this->throwNotFound('Parent section not found');
            }

            $results = [];
            foreach ($sections as $section) {
                $childSectionId = $this->asInt($section['sectionId'] ?? $section['childSectionId'] ?? null);
                $position = $this->asIntOrNull($section['position'] ?? null);
                $oldParentPageId = $this->asIntOrNull($section['oldParentPageId'] ?? null);
                $oldParentSectionId = $this->asIntOrNull($section['oldParentSectionId'] ?? null);

                $childSection = $this->sectionRepository->find($childSectionId);
                if (!$childSection) {
                    $this->throwNotFound("Child section {$childSectionId} not found");
                }

                // Prevent a section from being added anywhere inside its own
                // subtree. Walk all ancestors of the parent — if the child's ID
                // already appears there, the insertion would create a cycle and
                // cause infinite recursion in the page renderer.
                if ($this->wouldCreateCycle($parentSectionId, $childSectionId)) {
                    throw new ServiceException(
                        "Section {$childSectionId} cannot be added here: it is an ancestor of the target parent and would create a circular reference.",
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                $this->removeOldParentRelationships(
                    $oldParentPageId,
                    $oldParentSectionId,
                    $childSection,
                    $this->entityManager
                );

                // Flush old-parent removals before creating the new hierarchy row
                // to avoid identity-map conflicts on (parentSection, childSection).
                $this->entityManager->flush();

                $sectionHierarchy = $this->createSectionHierarchyRelationship(
                    $parentSection,
                    $childSection,
                    $position,
                    $this->entityManager
                );

                $results[] = [
                    'id'        => (int) $sectionHierarchy->getChildSection()?->getId(),
                    'position'  => $sectionHierarchy->getPosition(),
                    'sectionId' => $childSectionId,
                ];
            }

            // Single flush + single normalize for the whole batch
            $this->entityManager->flush();
            $this->positionManagementService->normalizeSectionHierarchyPositions($parentSectionId, true);

            // Page-level audit entry so the parent page's transaction history
            // captures nested-section attachments. The parent section ID is
            // included in the log payload for forensic traceability.
            $parentPage = $this->pageRepository->find($pageId);
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                (object) [
                    'id' => $pageId,
                    'keyword' => $parentPage?->getKeyword(),
                    'url' => $parentPage?->getUrl(),
                    'parent_section_id' => $parentSectionId,
                    'parent_section_name' => $parentSection->getName(),
                    'attached_sections' => array_column($results, 'sectionId'),
                ],
                "Attached " . count($results) . " section(s) under parent section '{$parentSection->getName()}' (ID: {$parentSectionId}) in page '{$parentPage?->getKeyword()}'"
            );

            // Cache invalidation: parent + every touched child + page + lists
            // Also bust every other page that shares the same parent section.
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $parentSection->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
            $this->invalidateSharedSectionPages($parentSectionId);
            foreach ($results as $row) {
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, $row['sectionId']);
            }
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();

            $this->entityManager->commit();

            return $results;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e instanceof ServiceException
                ? $e
                : new ServiceException(
                    'Failed to add sections to section: ' . $e->getMessage(),
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    ['previous' => $e]
                );
        }
    }

    /**
     * Detach a section from a page without destroying the section record.
     *
     * "Remove" means unlink only: the section is removed from the place where
     * it sits on this page (its direct rel_pages_sections row, or its
     * rel_sections_hierarchy row when it is nested under a parent section on
     * this page). The `sections` row itself is left intact so the same section
     * — e.g. a shared refContainer — keeps rendering on every other page that
     * references it. To destroy the record entirely use {@see deleteSection()}.
     *
     * @param int $pageId The ID of the page to detach from
     * @param int $sectionId The ID of the section to detach
     * @throws ServiceException If the page or the section/link is not found
     */
    public function removeSectionFromPage(int $pageId, int $sectionId): void
    {
        $this->entityManager->beginTransaction();
        try {
            $page = $this->pageRepository->find($pageId);
            if (!$page) {
                $this->throwNotFound('Page not found');
            }

            $this->userContextAwareService->checkAdminAccessById($pageId, 'update');

            $section = $this->entityManager->getRepository(Section::class)->find($sectionId);
            if (!$section) {
                $this->throwNotFound('Section not found');
            }
            $sectionName = $section->getName();

            // Prefer the direct page link; fall back to the hierarchy link when
            // the section is nested under a parent section on this page.
            $pageSection = $this->entityManager->getRepository(PagesSection::class)
                ->findOneBy(['page' => $page, 'section' => $sectionId]);

            if ($pageSection) {
                $this->entityManager->remove($pageSection);
                $this->entityManager->flush();
                $this->positionManagementService->normalizePageSectionPositions((int) $page->getId());
            } else {
                $hierarchyLink = $this->findHierarchyLinkForPage($page, $sectionId);
                if (!$hierarchyLink) {
                    $this->throwNotFound('Section is not associated with this page.');
                }
                $parentSectionId = (int) $hierarchyLink->getParentSection()?->getId();
                $this->entityManager->remove($hierarchyLink);
                $this->entityManager->flush();
                if ($parentSectionId > 0) {
                    $this->positionManagementService->normalizeSectionHierarchyPositions($parentSectionId, true);
                }
            }

            // Page-level audit entry — only the page tree changed; the section
            // row itself survives for its other usages.
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $page->getId(),
                (object) [
                    'id' => $page->getId(),
                    'keyword' => $page->getKeyword(),
                    'url' => $page->getUrl(),
                    'detached_section_id' => $sectionId,
                    'detached_section_name' => $sectionName,
                ],
                "Detached section '{$sectionName}' (ID: {$sectionId}) from page '{$page->getKeyword()}'"
            );

            $this->entityManager->commit();

            $this->invalidatePageAndSectionLists((int) $page->getId(), $sectionId);
            $this->invalidateSharedSectionPages($sectionId);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException('Failed to remove section from page: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR, ['previous' => $e]);
        }
    }

    /**
     * Remove multiple sections from a page
     * 
     * @param int $pageId The ID of the page
     * @param list<int> $sectionIds The List of IDs of the sections to remove
     * @return array<string, mixed>
     * @throws ServiceException If the relationship does not exist
     */
    public function bulkRemoveSections(int $pageId, array $sectionIds): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));

        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $this->userContextAwareService->checkAdminAccessById($pageId, 'update');

        $this->entityManager->beginTransaction();
        try {
            $pageSectionsToRemove = [];
            $sectionsToDelete = [];
            $parentSectionIdsToNormalize = [];
            $errors = [];

            foreach ($sectionIds as $sectionId) {
                $pageSection = $this->entityManager
                    ->getRepository(PagesSection::class)
                    ->findOneBy([
                        'page' => $page,
                        'section' => $sectionId
                    ]);

                if ($pageSection) {
                    $pageSectionsToRemove[] = $pageSection;
                    continue;
                }

                $section = $this->entityManager
                    ->getRepository(Section::class)
                    ->find($sectionId);

                if (!$section) {
                    $errors[] = [
                        'sectionId' => $sectionId,
                        'error' => 'Section not found'
                    ];
                    continue;
                }

                if (
                    !$this->sectionBelongsToPageHierarchy(
                        $page,
                        $sectionId,
                        $this->entityManager,
                        $this->sectionRepository
                    )
                ) {
                    $errors[] = [
                        'sectionId' => $sectionId,
                        'error' => 'Section not in page hierarchy'
                    ];
                    continue;
                }

                /** @var list<array<string, mixed>> $parentRows */
                $parentRows = $this->entityManager
                    ->createQueryBuilder()
                    ->select('IDENTITY(sh.parentSection) AS parent_id')
                    ->from(SectionsHierarchy::class, 'sh')
                    ->where('sh.childSection = :section')
                    ->setParameter('section', $section)
                    ->getQuery()
                    ->getArrayResult();

                foreach ($parentRows as $parentRow) {
                    if (isset($parentRow['parent_id'])) {
                        $parentSectionIdsToNormalize[] = $this->asInt($parentRow['parent_id']);
                    }
                }

                $sectionsToDelete[] = $section;
            }

            if ($errors !== []) {
                throw new ServiceException(
                    'Bulk remove sections failed validation',
                    Response::HTTP_BAD_REQUEST,
                    [
                        'deleted_count' => 0,
                        'errors' => $errors
                    ]
                );
            }

            // Audit each deleted section BEFORE removal so the entity
            // snapshot is preserved in the transaction log (mirrors
            // deleteSection()).
            $detachedSectionIds = [];
            foreach ($pageSectionsToRemove as $pageSection) {
                $sectionEntity = $pageSection->getSection();
                $detachedSectionIds[] = $sectionEntity?->getId();
                $this->entityManager->remove($pageSection);
            }

            $deletedSectionAudit = [];
            foreach ($sectionsToDelete as $section) {
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_DELETE,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'sections',
                    $section->getId(),
                    $section,
                    "Section deleted via bulkRemoveSections: '{$section->getName()}' (ID: {$section->getId()}) from page '{$page->getKeyword()}'"
                );
                $deletedSectionAudit[] = [
                    'id' => $section->getId(),
                    'name' => $section->getName(),
                ];
                $this->removeAllSectionRelationships($section, $this->entityManager);
                $this->entityManager->remove($section);
            }

            $deleted = count($pageSectionsToRemove) + count($sectionsToDelete);

            $this->entityManager->flush();

            // Single page-level audit entry that summarises the batch.
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $page->getId(),
                (object) [
                    'id' => $page->getId(),
                    'keyword' => $page->getKeyword(),
                    'url' => $page->getUrl(),
                    'detached_section_ids' => array_values(array_filter($detachedSectionIds)),
                    'deleted_sections' => $deletedSectionAudit,
                    'total_removed' => $deleted,
                ],
                "Bulk-removed {$deleted} section(s) from page '{$page->getKeyword()}'"
            );

            if ($pageSectionsToRemove !== []) {
                $this->positionManagementService->normalizePageSectionPositions((int) $page->getId());
            }

            foreach (array_unique($parentSectionIdsToNormalize) as $parentSectionId) {
                $this->positionManagementService->normalizeSectionHierarchyPositions($parentSectionId);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());
            foreach ($sectionIds as $sid) {
                $this->invalidateSharedSectionPages($sid);
            }
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();

            return [
                'deleted_count' => $deleted,
                'errors' => []
            ];

        } catch (ServiceException $e) {
            $this->entityManager->rollback();
            throw $e;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Bulk remove failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e]
            );
        }
    }

    /**
     * Remove a section from another section
     * 
     * @param int $pageId The page ID
     * @param int $parentSectionId The ID of the parent section
     * @param int $childSectionId The ID of the child section
     * @throws ServiceException If the relationship does not exist
     */
    public function removeSectionFromSection(int $pageId, int $parentSectionId, int $childSectionId): void
    {
        // Permission check
       $this->userContextAwareService->checkAdminAccessById($pageId, 'update');
        $this->checkSectionInPage($pageId, $parentSectionId);
        
        $this->entityManager->beginTransaction();
        try {
            $sectionHierarchy = $this->entityManager->getRepository(SectionsHierarchy::class)
                ->findOneBy(['parentSection' => $parentSectionId, 'childSection' => $childSectionId]);
            if (!$sectionHierarchy) {
                $this->throwNotFound('Section hierarchy relationship not found.');
            }

            // Capture entity references for the audit log before removal.
            $parentSectionPre = $sectionHierarchy->getParentSection();
            $childSectionPre = $sectionHierarchy->getChildSection();
            $parentName = $parentSectionPre?->getName();
            $childName = $childSectionPre?->getName();

            $this->entityManager->remove($sectionHierarchy);
            $this->entityManager->flush();
            $this->positionManagementService->normalizeSectionHierarchyPositions($parentSectionId, true);

            // Page-level audit entry — the parent page's section tree was
            // modified even though both section rows still exist.
            $pageEntity = $this->pageRepository->find($pageId);
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                (object) [
                    'id' => $pageId,
                    'keyword' => $pageEntity?->getKeyword(),
                    'url' => $pageEntity?->getUrl(),
                    'parent_section_id' => $parentSectionId,
                    'parent_section_name' => $parentName,
                    'child_section_id' => $childSectionId,
                    'child_section_name' => $childName,
                ],
                "Detached child section '{$childName}' (ID: {$childSectionId}) from parent section '{$parentName}' (ID: {$parentSectionId}) in page '{$pageEntity?->getKeyword()}'"
            );

            // Invalidate section caches + every other page sharing the parent section.
            $parentSection = $this->sectionRepository->find($parentSectionId);
            $childSection = $this->sectionRepository->find($childSectionId);

            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
            $this->invalidateSharedSectionPages($parentSectionId);

            if ($parentSection) {
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateItem("section_fields_{$parentSection->getId()}");
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $parentSection->getId());
            }
            if ($childSection) {
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateItem("section_fields_{$childSection->getId()}");
                $this->cache
                    ->withCategory(CacheService::CATEGORY_SECTIONS)
                    ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $childSection->getId());
            }

            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();
            
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException('Failed to remove section from section: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR, ['previous' => $e]);
        }
    }

    /**
     * Permanently delete a section record.
     *
     * Destroys the section row and its own relationships. Each page that
     * referenced it is independent and handles the missing section on its own.
     * To merely unlink a section from one page while keeping the record for
     * other usages, use {@see removeSectionFromPage()}.
     *
     * @param int $sectionId The ID of the section to delete
     * @throws ServiceException If the section is not found
     */
    public function deleteSection(int $sectionId): void
    {
        $section = $this->sectionRepository->find($sectionId);
        if (!$section) {
            $this->throwNotFound('Section not found');
        }

        $this->entityManager->beginTransaction();
        try {
            $this->destroySection($section);
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException('Failed to delete section: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR, ['previous' => $e]);
        }

        $this->invalidatePageAndSectionLists(null, $sectionId);
    }

    /**
     * Destroy a single section row and all of its relationships inside the
     * caller's open transaction. Logs the deletion before removal so the audit
     * trail keeps the full entity snapshot.
     */
    private function destroySection(Section $section): void
    {
        $this->transactionService->logTransaction(
            LookupService::TRANSACTION_TYPES_DELETE,
            LookupService::TRANSACTION_BY_BY_USER,
            'sections',
            $section->getId(),
            $section,
            'Section deleted: ' . $section->getName() . ' (ID: ' . $section->getId() . ')'
        );

        $this->removeAllSectionRelationships($section, $this->entityManager);
        $this->entityManager->remove($section);
        $this->entityManager->flush();
    }

    /**
     * Locate the rel_sections_hierarchy row that links $sectionId to a parent
     * section that belongs to $page. Returns null when the section is not a
     * nested child anywhere in this page's hierarchy.
     */
    private function findHierarchyLinkForPage(Page $page, int $sectionId): ?SectionsHierarchy
    {
        $links = $this->entityManager->getRepository(SectionsHierarchy::class)
            ->findBy(['childSection' => $sectionId]);

        foreach ($links as $link) {
            $parentId = (int) $link->getParentSection()?->getId();
            if ($parentId > 0 && $this->sectionBelongsToPageHierarchy($page, $parentId, $this->entityManager, $this->sectionRepository)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Shared post-write cache invalidation: the section's own scope, the
     * owning page scope (when known) and all page + section list caches
     * (unused_sections / ref_containers must reflect the change immediately).
     */
    private function invalidatePageAndSectionLists(?int $pageId, int $sectionId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, $sectionId);
        if ($pageId !== null) {
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        }
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateAllListsInCategory();
    }

    /**
     * Bust the page-scope cache for every page that references $sectionId
     * anywhere in its hierarchy. Needed so that all pages sharing a refContainer
     * see fresh data when that container or any of its children change.
     */
    private function invalidateSharedSectionPages(int $sectionId): void
    {
        foreach ($this->sectionRepository->getPageIdsContainingSection($sectionId) as $pid) {
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pid);
        }
    }

    /**
     * Check if the section is in the page
     * 
     * Important check for api calls in order to manipulate sections. 
     * 
     * @param int $pageId The page ID
     * @param int $sectionId The section ID
     * @throws ServiceException If the section is not found or access denied
     */
    public function checkSectionInPage(int $pageId, int $sectionId): void
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        // Fetch all sections (flat) for this page
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId((int) $page->getId());
        // Extract all section IDs
        $sectionIds = array_map(function ($section) {
            return isset($section['id']) ? $this->asInt($section['id']) : null;
        }, $flatSections);
        if (!in_array($sectionId, $sectionIds, true)) {
            $this->throwForbidden('Access denied: Section does not belong to page');
        }
    }

    /**
     * Returns true if placing $childId under $parentId would create a cycle.
     *
     * Walks the full ancestor chain of $parentId upward through
     * rel_sections_hierarchy. If $childId already appears there (including
     * $parentId itself), adding it as a child would make the section a
     * descendant of itself and cause infinite recursion in the renderer.
     */
    private function wouldCreateCycle(int $parentId, int $childId): bool
    {
        // A section is always its own ancestor in terms of identity.
        if ($parentId === $childId) {
            return true;
        }

        $conn = $this->entityManager->getConnection();

        /** @var array<int, array{id: int|string}> $rows */
        $rows = $conn->fetchAllAssociative(<<<SQL
            WITH RECURSIVE ancestors AS (
                SELECT sh.id_parent_section AS id
                FROM rel_sections_hierarchy sh
                WHERE sh.id_child_section = :start

                UNION ALL

                SELECT sh.id_parent_section
                FROM rel_sections_hierarchy sh
                INNER JOIN ancestors a ON sh.id_child_section = a.id
            )
            SELECT id FROM ancestors WHERE id = :child
        SQL, ['start' => $parentId, 'child' => $childId]);

        return $rows !== [];
    }
}
