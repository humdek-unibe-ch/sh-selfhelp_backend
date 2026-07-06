<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageTypeField;
use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Repository\PageTypeRepository;
use App\Repository\PagesFieldsTranslationRepository;
use App\Repository\RoleDataAccessRepository;
use App\Repository\SectionRepository;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageFieldService;
use App\Service\CMS\Admin\SectionRelationshipService;
use App\Service\CMS\Admin\Traits\TranslationManagerTrait;
use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\NavigationAssignmentService;
use App\Service\CMS\NavigationCacheInvalidator;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Core\BaseService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\Core\UserContextAwareService;
use App\Service\Auth\UserContextService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling page-related operations in the admin panel
 * ENTITY RULE
 */
class AdminPageService extends BaseService
{
    use TranslationManagerTrait;

    // ACL group name constants
    private const GROUP_ADMIN = 'admin';
    private const GROUP_SUBJECT = 'subject';
    private const GROUP_THERAPIST = 'therapist';

    /**
     * Constructor
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly PageTypeRepository $pageTypeRepository,
        private readonly TransactionService $transactionService,
        private readonly SectionUtilityService $sectionUtilityService,
        private readonly PageFieldService $pageFieldService,
        private readonly SectionRelationshipService $sectionRelationshipService,
        private readonly ACLService $aclService,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly CacheService $cache,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly PageRouteService $pageRouteService,
        private readonly PageParentRouteSyncService $pageParentRouteSyncService,
        private readonly NavigationAssignmentService $navigationAssignmentService,
        private readonly NavigationCacheInvalidator $navigationCacheInvalidator,
        private readonly PagesFieldsTranslationRepository $pagesFieldsTranslationRepository,
        private readonly CmsPreferenceService $cmsPreferenceService,
    ) {
    }

    /**
     * Get page with its fields and translations
     * 
     * @param int $pageId The page ID
     * @return array<string, mixed> The page with its fields and translations
     * @throws ServiceException If page not found or access denied
     */
    public function getPageWithFields(int $pageId): array
    {
        return $this->pageFieldService->getPageWithFields($pageId);
    }

    /**
     * Get page sections with entity scope caching
     * 
     * @param int $pageId The page ID
     * @return list<array<string, mixed>> The page sections in a hierarchical structure
     * @throws \Exception If page not found
     */
    public function getPageSections(int $pageId): array
    {
        $cacheKey = "page_sections_{$pageId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId)
            ->getItem($cacheKey, function () use ($pageId) {
                $page = $this->pageRepository->find($pageId);
                if (!$page) {
                    $this->throwNotFound('Page not found');
                }

                $this->userContextAwareService->checkAdminAccess((string) $page->getKeyword(), 'select');

                // Cache with entity scope for this specific page
                $result = $this->cache
                    ->withCategory(CacheService::CATEGORY_PAGES)
                    ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId)
                    ->getItem("page_sections_scoped_{$pageId}", function () use ($page) {
                    // Call stored procedure for hierarchical sections
                    $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId((int) $page->getId());
                    return $this->sectionUtilityService->buildNestedSections($flatSections, false);
                });

                return $result;
            });
    }

    /** Private methods */
    /**
     * Create a new page
     * 
     * @param string $keyword Unique keyword for the page
     * @param string $pageAccessTypeCode Code of the page access type lookup
     * @param bool $isHeadless Whether the page is headless
     * @param bool $isOpenAccess Whether the page has open access
     * @param string|null $url URL for the page
     * @param int|null $parentId ID of the parent page
     * @param string $surfaceCode CMS-in-CMS surface (`public` | `cms`); defaults to `public`
     * @param list<int> $accessGroups Extra group ids that should be granted access to the page
     * @param list<array<string, mixed>>|null $navigationAssignments Menu builder assignments for the new page
     * @param list<array<string, mixed>>|null $initialRoutes Public route handling for the new page:
     *        `null` (default) auto-creates one canonical, active route derived from `$url` so the
     *        page is reachable immediately; an explicit non-empty set is synced as-is (wizard); an
     *        empty array `[]` skips route creation entirely (importer manages routes itself).
     * @param bool $syncUrlWithParent When true and a parent page is set, derive URL + canonical route from the parent.
     * @param string|null $oldRoutePolicy Override for old-route handling (`keep_alias`, `remove_old_route`).
     * 
     * @return Page The created page entity
     * @throws ServiceException If validation fails or required entities not found
     */
    public function createPage(
        string $keyword,
        string $pageAccessTypeCode,
        bool $isHeadless = false,
        bool $isOpenAccess = false,
        ?string $url = null,
        ?int $parentId = null,
        string $surfaceCode = LookupService::PAGE_SURFACE_PUBLIC,
        array $accessGroups = [],
        ?array $navigationAssignments = null,
        ?array $initialRoutes = null,
        bool $syncUrlWithParent = false,
        ?string $oldRoutePolicy = null,
    ): Page {

        // Check if keyword already exists
        if ($this->pageRepository->findOneBy(['keyword' => $keyword])) {
            $this->throwConflict("Page with keyword '{$keyword}' already exists");
        }

        // Check if url already exists
        if ($this->pageRepository->findOneBy(['url' => $url])) {
            $this->throwConflict("Page with url '{$url}' already exists");
        }

        // Get page access type by code
        $pageAccessType = $this->lookupService->findByTypeAndCode(
            LookupService::PAGE_ACCESS_TYPES,
            $pageAccessTypeCode
        );
        if (!$pageAccessType) {
            $this->throwNotFound("Page access type with code '{$pageAccessTypeCode}' not found");
        }

        // Resolve the CMS-in-CMS surface (public|cms). A NULL FK would resolve
        // to `public` at read time, but we always persist the explicit lookup
        // so the admin grouping/ACL defaults are unambiguous.
        $isCmsSurface = $surfaceCode === LookupService::PAGE_SURFACE_CMS;
        $pageSurface = $this->lookupService->findByTypeAndCode(
            LookupService::PAGE_SURFACE,
            $surfaceCode
        );
        if (!$pageSurface) {
            $this->throwNotFound("Page surface with code '{$surfaceCode}' not found");
        }

        // Get parent page if provided
        $parentPage = null;
        if ($parentId) {
            $parentPage = $this->pageRepository->find($parentId);
            if (!$parentPage) {
                $this->throwNotFound("Parent page with ID {$parentId} not found");
            }
        }

        // Get default page type (experiment)
        $pageType = $this->pageTypeRepository->findOneBy(['name' => 'experiment']);
        if (!$pageType) {
            $this->throwNotFound("Default page type 'experiment' not found");
        }

        // Create new page entity
        $page = new Page();
        $page->setKeyword($keyword);
        $page->setPageAccessType($pageAccessType);
        $page->setPageSurface($pageSurface);
        $page->setIsHeadless($isHeadless);
        $page->setIsOpenAccess($isOpenAccess);
        $page->setUrl($url);
        $page->setParentPage($parentPage);
        $page->setPageType($pageType);
        $page->setIsSystem(false);

        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->persist($page);
            $this->entityManager->flush(); // To get the page ID

            // Fetch groups by name
            $groupRepo = $this->entityManager->getRepository(Group::class);
            $adminGroup = $groupRepo->findOneBy(['name' => self::GROUP_ADMIN]);
            $subjectGroup = $groupRepo->findOneBy(['name' => self::GROUP_SUBJECT]);
            $therapistGroup = $groupRepo->findOneBy(['name' => self::GROUP_THERAPIST]);
            if (!$adminGroup || !$subjectGroup || !$therapistGroup) {
                throw new ServiceException('One or more required groups not found.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Admin always gets full access, regardless of surface. Track the
            // group ids already granted so author-selected groups never create
            // duplicate ACL rows.
            $grantedGroupIds = [];
            $this->aclService->addGroupAcl($page, $adminGroup, true, true, true, true, $this->entityManager);
            $grantedGroupIds[(int) $adminGroup->getId()] = true;

            if (!$isCmsSurface) {
                // Public website pages keep the historical default reader ACL
                // for the subject + therapist groups (select only).
                $this->aclService->addGroupAcl($page, $subjectGroup, true, false, false, false, $this->entityManager);
                $grantedGroupIds[(int) $subjectGroup->getId()] = true;
                $this->aclService->addGroupAcl($page, $therapistGroup, true, false, false, false, $this->entityManager);
                $grantedGroupIds[(int) $therapistGroup->getId()] = true;
            }
            // CMS application pages stay admin/editor-only: no default reader
            // groups are added; the author grants editor groups explicitly.

            // Author-selected groups: full CRUD on cms-app pages (they are the
            // app's editors), read-only on public pages (they are viewers).
            foreach ($accessGroups as $groupId) {
                if (isset($grantedGroupIds[$groupId])) {
                    continue;
                }
                $group = $groupRepo->find($groupId);
                if (!$group) {
                    $this->throwNotFound("Group with ID {$groupId} not found");
                }
                $this->aclService->addGroupAcl(
                    $page,
                    $group,
                    true,
                    $isCmsSurface,
                    $isCmsSurface,
                    $isCmsSurface,
                    $this->entityManager
                );
                $grantedGroupIds[$groupId] = true;
            }


            if (is_array($navigationAssignments) && $navigationAssignments !== []) {
                $this->navigationAssignmentService->applyAssignmentsForPage($page, $navigationAssignments);
            }

            $this->entityManager->flush();

            // Auto-create the page's public route so a new page is reachable by
            // URL immediately. The create modal generates a URL pattern but
            // historically never persisted it as a `page_route`, so every new
            // page landed with "no active route". `syncRoutes` runs the global
            // conflict validator, enforces a single canonical, and invalidates
            // the resolver cache (it shares this transaction). Callers that own
            // their route set (importer/wizard) pass `$initialRoutes` explicitly.
            if ($initialRoutes === null) {
                if ($syncUrlWithParent && $parentPage instanceof Page) {
                    $this->pageParentRouteSyncService->syncPageUrlWithParent($page, $oldRoutePolicy);
                } else {
                    $derived = $url !== null ? PageRouteService::buildCanonicalRouteFromUrl($url) : null;
                    if ($derived !== null) {
                        $this->pageRouteService->syncRoutes((int) $page->getId(), [$derived]);
                    }
                }
            } elseif ($initialRoutes !== []) {
                $this->pageRouteService->syncRoutes((int) $page->getId(), $initialRoutes);
            }

            // Log the page creation transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $page->getId(),
                true,
                'Page created with keyword: ' . $keyword
            );
            // Invalidate all page lists since a new page was created
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateAllListsInCategory();
            $this->navigationCacheInvalidator->invalidateForPage((int) $page->getId());

            $this->entityManager->commit();

        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to create page and assign ACLs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
        return $page;
    }

    /**
     * Update an existing page and its field translations
     * 
     * @param int $pageId The ID of the page to update
     * @param array<string, mixed> $pageData The page data to update
     * @param list<array<string, mixed>> $fields The fields to update
     * @return Page The updated page
     * @throws ServiceException If page not found or access denied
     */
    public function updatePage(int $pageId, array $pageData, array $fields): Page
    {
        $this->entityManager->beginTransaction();

        try {
            // Find the page
            $page = $this->pageRepository->find($pageId);
            if (!$page) {
                $this->throwNotFound('Page not found');
            }

            // Check if user has update access to the page
            $this->userContextAwareService->checkAdminAccess((string) $page->getKeyword(), 'update');

            // Store original page for transaction logging
            $originalPage = clone $page;

            // Update page properties
            // Use array_key_exists instead of isset to handle explicit null values
            if (array_key_exists('url', $pageData)) {
                $page->setUrl($this->asStringOrNull($pageData['url']));
            }

            if (array_key_exists('headless', $pageData)) {
                $page->setIsHeadless((bool) $pageData['headless']);
            }

            if (array_key_exists('openAccess', $pageData)) {
                $page->setIsOpenAccess($pageData['openAccess'] === null ? null : (bool) $pageData['openAccess']);
            }

            if (array_key_exists('pageAccessTypeCode', $pageData)) {
                if ($pageData['pageAccessTypeCode'] === null) {
                    // Set to null if explicitly provided as null
                    $page->setPageAccessType(null);
                } else {
                    // Find the page access type lookup
                    $pageAccessType = $this->lookupService->findByTypeAndCode(
                        LookupService::PAGE_ACCESS_TYPES,
                        $this->asString($pageData['pageAccessTypeCode'])
                    );

                    if (!$pageAccessType) {
                        throw new ServiceException(
                            'Invalid page access type',
                            Response::HTTP_BAD_REQUEST
                        );
                    }

                    $page->setPageAccessType($pageAccessType);
                }
            }

            // CMS-in-CMS surface (public|cms). NULL clears the FK (resolves to
            // `public` at read time); any other value must be a valid lookup.
            if (array_key_exists('surface', $pageData)) {
                if ($pageData['surface'] === null) {
                    $page->setPageSurface(null);
                } else {
                    $pageSurface = $this->lookupService->findByTypeAndCode(
                        LookupService::PAGE_SURFACE,
                        $this->asString($pageData['surface'])
                    );
                    if (!$pageSurface) {
                        throw new ServiceException(
                            'Invalid page surface',
                            Response::HTTP_BAD_REQUEST
                        );
                    }
                    $page->setPageSurface($pageSurface);
                }
            }

            if (array_key_exists('parent', $pageData)) {
                $parentId = $pageData['parent'];
                if ($parentId === null) {
                    $page->setParentPage(null);
                } else {
                    $parentPage = $this->pageRepository->find($this->asInt($parentId));
                    if (!$parentPage) {
                        $this->throwNotFound('Parent page not found');
                    }
                    $page->setParentPage($parentPage);
                }
            }

            $syncUrlWithParent = (bool) ($pageData['syncUrlWithParent'] ?? false);
            $oldRoutePolicy = isset($pageData['oldRoutePolicy']) && is_string($pageData['oldRoutePolicy'])
                ? $pageData['oldRoutePolicy']
                : null;
            if ($syncUrlWithParent) {
                $this->pageParentRouteSyncService->syncPageUrlWithParent($page, $oldRoutePolicy);
            }

            // CMS-editable public routes (issue #30). The locked Routes panel
            // sends the full desired set; the route service syncs (create/
            // update/delete), runs the global conflict validator, enforces a
            // single canonical, and invalidates the resolver cache.
            if (array_key_exists('routes', $pageData) && is_array($pageData['routes'])) {
                $routesInput = [];
                foreach ($pageData['routes'] as $route) {
                    if (is_array($route)) {
                        $assoc = [];
                        foreach ($route as $routeKey => $routeValue) {
                            $assoc[(string) $routeKey] = $routeValue;
                        }
                        $routesInput[] = $assoc;
                    }
                }
                $this->pageRouteService->syncRoutes((int) $page->getId(), $routesInput);
            }

            // Flush page changes first to ensure we have a valid page ID
            $this->entityManager->flush();

            // Validate that all fields belong to the page's page type
            if (!empty($fields)) {
                $fieldIds = array_map(fn($v): int => $this->asInt($v), array_column($fields, 'fieldId'));
                // Get the page type ID from the page entity
                $pageType = $page->getPageType();
                if (!$pageType) {
                    throw new ServiceException(
                        sprintf("Page %s does not have a page type assigned", $page->getKeyword()),
                        Response::HTTP_BAD_REQUEST
                    );
                }
                $pageTypeId = $pageType->getId();

                // Get all valid field IDs for this page type from rel_fields_page_types
                /** @var list<array<string, mixed>> $validFieldRows */
                $validFieldRows = $this->entityManager->getRepository(PageTypeField::class)
                    ->createQueryBuilder('ptf')
                    ->select('f.id')
                    ->leftJoin('ptf.field', 'f')
                    ->leftJoin('ptf.pageType', 'pt')
                    ->where('pt.id = :pageTypeId')
                    ->andWhere('f.id IN (:fieldIds)')
                    ->setParameter('pageTypeId', $pageTypeId)
                    ->setParameter('fieldIds', $fieldIds)
                    ->getQuery()
                    ->getScalarResult();

                $validFieldIds = array_map(fn($v): int => $this->asInt($v), array_column($validFieldRows, 'id'));
                $invalidFieldIds = array_diff($fieldIds, $validFieldIds);

                if (!empty($invalidFieldIds)) {
                    throw new ServiceException(
                        sprintf(
                            "Fields [%s] do not belong to page type %s (page %s)",
                            implode(', ', $invalidFieldIds),
                            (string) $pageType->getName(),
                            (string) $page->getKeyword()
                        ),
                        Response::HTTP_BAD_REQUEST
                    );
                }
            }

            // Update field translations using dedicated service
            $this->pageFieldService->updatePageFields($page, $fields);

            // Flush all changes again
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $page->getId(),
                (object) array("old_page" => $originalPage, "new_page" => $page),
                'Page updated: ' . $page->getKeyword() . ' (ID: ' . $page->getId() . ')'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific page
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId())
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateAllListsInCategory();
            $this->navigationCacheInvalidator->invalidateForPage((int) $page->getId());

            // Check if this is the CMS preferences page
            if ($page->getKeyword() == CmsPreferenceService::SH_CMS_PREFERENCES_KEYWORD) {
                // Clear ALL cache categories when CMS preferences are updated
                foreach (CacheService::ALL_CATEGORIES as $category) {
                    $this->cache
                        ->withCategory($category)
                        ->invalidateCategory();
                }
            }

            return $page;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to update page: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Delete a page by its ID
     * 
     * @param int $pageId The ID of the page to delete
     * @return Page
     * @throws ServiceException If page not found or access denied
     */
    public function deletePage(int $pageId): Page
    {
        $this->entityManager->beginTransaction();

        try {
            $page = $this->pageRepository->find($pageId);

            if (!$page) {
                $this->throwNotFound('Page not found');
            }

            $deleted_page = clone $page;

            // Check if user has delete access to the page
            $this->userContextAwareService->checkAdminAccess((string) $page->getKeyword(), 'delete');

            // Block deletion of system pages. Pages flagged as `is_system = 1`
            // (e.g. the GDPR `/privacy` notice seeded by migration
            // Version20260601000500) MUST remain reachable on every install,
            // because regulators expect a privacy notice to be permanently
            // available even when admins customise the rest of the CMS.
            // Admins can still edit / extend / translate the content; only
            // hard-deletion of the page row is blocked.
            if ($page->isSystem()) {
                throw new ServiceException(
                    'Cannot delete system pages. This page is marked as a system page and is required for the platform to function correctly.',
                    Response::HTTP_FORBIDDEN
                );
            }

            // Check if the page has children
            $children = $this->pageRepository->findBy(['parentPage' => $page->getId()]);
            if (count($children) > 0) {
                throw new ServiceException(
                    'Cannot delete page with children. Remove child pages first.',
                    Response::HTTP_BAD_REQUEST
                );
            }

            // ACL entries will be automatically deleted via foreign key constraints with cascade on delete

            // Delete page fields translations
            $this->entityManager->createQuery(
                'DELETE FROM App\\Entity\\PagesFieldsTranslation pft WHERE pft.page = :page'
            )
                ->setParameter('page', $page)
                ->execute();

            // Clear the published version reference to avoid foreign key constraint violations
            // Use direct SQL to avoid Doctrine trying to load non-existent PageVersion entities
            $this->entityManager->createQuery(
                'UPDATE App\\Entity\\Page p SET p.publishedVersion = NULL WHERE p.id = :pageId'
            )
                ->setParameter('pageId', $page->getId())
                ->execute();

            // Store page keyword for logging before deletion
            $pageKeywordForLog = $page->getKeyword();
            $pageIdForLog = (int) $page->getId();

            // Delete the page
            $this->entityManager->remove($page);
            $this->entityManager->flush();


            // Log the page deletion transaction after commit to avoid EntityManager conflicts
            // This ensures we capture the page data even after it's removed from the database
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageIdForLog,
                $deleted_page, // Pass the page object directly instead of a boolean
                'Page deleted with keyword: ' . $pageKeywordForLog
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific page
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageIdForLog);
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateAllListsInCategory();
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateAllListsInCategory();

            // The deleted page's `page_routes` rows are removed by FK ON DELETE
            // CASCADE, but the DB-driven public-path resolver caches its
            // active-row snapshot — drop it so the deleted page's pattern stops
            // resolving (create/update already invalidate via syncRoutes()).
            $this->pageRouteService->invalidateResolverCache();
            $this->navigationCacheInvalidator->invalidateForPageDeletion($pageIdForLog);

            return $deleted_page;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to delete page: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Add one or more sections to a page in a single atomic operation.
     *
     * Delegates the whole batch to the relationship service (one transaction)
     * and then performs a top-level cache invalidation pass for the page and
     * every touched section.
     *
     * @param int   $pageId   The ID of the page to attach the sections to
     * @param list<array<string, mixed>> $sections Batch of section payloads. Each item must contain
     *                        `sectionId` and may include `position` and
     *                        `oldParentSectionId`.
     * @return array<int, array{id: int, position: int|null}> Result for each
     *         input section, in the same order. Internal `sectionId` is stripped.
     * @throws ServiceException If page or section not found or access denied
     */
    public function addSectionToPage(int $pageId, array $sections): array
    {
        $results = $this->sectionRelationshipService->addSectionToPage($pageId, $sections);

        // Top-level cache invalidation for page + every touched section + lists
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        foreach ($results as $row) {
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, $row['sectionId']);
        }
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateAllListsInCategory();

        return array_map(
            static fn(array $row): array => ['id' => $row['id'], 'position' => $row['position']],
            $results
        );
    }

    /**
     * Detach a section from a page without destroying the section record.
     *
     * Unlinks the section from this page only (its direct page link, or its
     * hierarchy link when nested). The section row survives for every other
     * page that references it. To destroy the record entirely, use the
     * page-independent delete endpoint (AdminSectionController::deleteSection).
     * Cache invalidation is handled inside the relationship service.
     *
     * @param int $pageId The ID of the page.
     * @param int $sectionId The ID of the section to detach.
     * @throws ServiceException If the page or the section/link is not found.
     */
    public function removeSectionFromPage(int $pageId, int $sectionId): void
    {
        $this->sectionRelationshipService->removeSectionFromPage($pageId, $sectionId);
    }

    /**
     * Remove multiple sections from a page, invalidaes all cache entries to the affected entities.
     * 
     * @param int $pageId The ID of the page
     * @param list<int> $sectionIds The List of IDs of the sections to remove
     * @return array<string, mixed>
     * @throws ServiceException If the relationship does not exist
     */
    public function bulkRemoveSectionsFromPage(int $pageId, array $sectionIds): array
    {
        $result = $this->sectionRelationshipService
            ->bulkRemoveSections($pageId, $sectionIds);

        // Page cache
        $this->cache->invalidateEntityScope(
            CacheService::ENTITY_SCOPE_PAGE,
            $pageId
        );

        // Section cache (bulk-safe)
        foreach ($sectionIds as $sectionId) {
            $this->cache->invalidateEntityScope(
                CacheService::ENTITY_SCOPE_SECTION,
                $sectionId
            );
        }

        // Global invalidations
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();

        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateAllListsInCategory();

        return $result;
    }

    /**
     * Get all pages for admin purposes without ACL filtering
     * Returns pages in the same format as PageService for compatibility
     *
     * @return list<array<string, mixed>> Array of pages in hierarchical structure
     */
    public function getAllPagesForAdmin(): array
    {
        // Get current user for caching. This endpoint is admin-only (the
        // firewall requires authentication), so the guest branch is never
        // reached in practice; we use the shared sentinel for consistency.
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID;

        // Cache key for admin pages (no ACL filtering)
        $cacheKey = "admin_pages";

        $pages = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList($cacheKey, function () {
                // Get all pages from repository
                $pages = $this->pageRepository->findAll();

                $pageIds = array_values(array_filter(array_map(
                    static fn (Page $page): ?int => $page->getId(),
                    $pages,
                )));
                $membershipByPage = $this->navigationAssignmentService->getMembershipBadgesForPageIds($pageIds);

                // Convert entities to array manually for better control and performance
                $allPages = [];
                foreach ($pages as $page) {
                    $pageId = (int) $page->getId();
                    $allPages[] = [
                        'id_pages' => $pageId,
                        'id_parent_page' => $page->getParentPage() ? $page->getParentPage()->getId() : null,
                        'keyword' => $page->getKeyword(),
                        'url' => $page->getUrl(),
                        'is_headless' => $page->isHeadless() ? 1 : 0,
                        'is_open_access' => $page->isOpenAccess() ? 1 : 0,
                        'is_system' => $page->isSystem() ? 1 : 0,
                        'id_page_access_types' => $page->getPageAccessType() ? $page->getPageAccessType()->getId() : null,
                        'id_page_types' => $page->getPageType() ? $page->getPageType()->getId() : null,
                        'page_surface' => $page->getPageSurfaceCode(),
                        'navigationMembership' => $membershipByPage[$pageId] ?? [],
                    ];
                }

                return $allPages;
            });

        return $pages;
    }

    /**
     * Get filtered pages with permission-based access control
     * Includes proper caching with user scope
     * Uses RoleDataAccessRepository optimized methods
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function getFilteredPages(int $userId, array $filters = []): array
    {
        // Create cache key based on user and filters
        $cacheKey = "filtered_pages_{$userId}_" . md5(serialize($filters));

        return $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList(
                $cacheKey,
                fn() => $this->fetchFilteredPagesFromRepository($userId, $filters)
            );
    }

    /**
     * Check if user can access a specific page for a given permission
     */
    public function canAccessPage(int $userId, int $pageId, int $permission): bool
    {
        return $this->dataAccessSecurityService->hasPermission(
            $userId,
            LookupService::RESOURCE_TYPES_PAGES,
            $pageId,
            $permission
        );
    }

    /**
     * Fetch filtered pages from repository with permission checking
     * Uses RoleDataAccessRepository optimized SQL queries
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function fetchFilteredPagesFromRepository(int $userId, array $filters): array
    {
        // Get resource type ID
        $resourceTypeId = $this->lookupService->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES
        );

        if (!$resourceTypeId) {
            return [];
        }

        // Check if user is admin - use repository method for all pages
        if ($this->dataAccessSecurityService->userHasAdminRole($userId)) {
            $pages = $this->roleDataAccessRepository->getAllPagesWithFullPermissions();
        } else {
            // Use repository method for accessible pages
            $pages = $this->roleDataAccessRepository->getAccessiblePagesForUser($userId, $resourceTypeId);
        }

        // Apply additional filters if provided (keyword, type).
        // The repository already returns canonical snake_case columns
        // (id_parent_page, id_page_types, id_page_access_types), so we
        // can filter against them directly.
        if (!empty($filters)) {
            $pages = array_filter($pages, function ($page) use ($filters) {
                // Filter by keyword
                if (isset($filters['keyword']) && $filters['keyword']) {
                    $keyword = isset($page['keyword']) ? $this->asString($page['keyword']) : '';
                    if (stripos($keyword, $this->asString($filters['keyword'])) === false) {
                        return false;
                    }
                }

                // Filter by page type
                if (isset($filters['type']) && $filters['type']) {
                    if (($page['id_page_types'] ?? null) != $filters['type']) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $this->attachNavigationMembership($this->attachPageTitles(array_values($pages)));
    }

    /**
     * Attach `navigationMembership` badges (menu key + item id per active menu
     * item referencing the page) so the admin navbar/search can group pages by
     * where they actually appear instead of listing everything as content.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function attachNavigationMembership(array $pages): array
    {
        $pageIds = [];
        foreach ($pages as $page) {
            if (isset($page['id_pages']) && is_numeric($page['id_pages'])) {
                $pageIds[] = (int) $page['id_pages'];
            }
        }
        $membershipByPage = $this->navigationAssignmentService->getMembershipBadgesForPageIds($pageIds);

        foreach ($pages as &$page) {
            $pageId = isset($page['id_pages']) && is_numeric($page['id_pages']) ? (int) $page['id_pages'] : null;
            $page['navigationMembership'] = $pageId !== null ? ($membershipByPage[$pageId] ?? []) : [];
        }
        unset($page);

        return $pages;
    }

    /**
     * Attach human titles to admin page rows: `title` in the default CMS
     * language plus a `titles` list per language so pickers can label pages
     * in the admin's current UI language (no per-language refetch needed).
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function attachPageTitles(array $pages): array
    {
        $pageIds = [];
        foreach ($pages as $page) {
            if (isset($page['id_pages']) && is_numeric($page['id_pages'])) {
                $pageIds[] = (int) $page['id_pages'];
            }
        }
        $titlesByPage = $this->pagesFieldsTranslationRepository->fetchTitleByLanguageForPages($pageIds);
        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();

        foreach ($pages as &$page) {
            $pageId = isset($page['id_pages']) && is_numeric($page['id_pages']) ? (int) $page['id_pages'] : null;
            $byLanguage = $pageId !== null ? ($titlesByPage[$pageId] ?? []) : [];
            $default = null;
            if ($defaultLanguageId !== null && isset($byLanguage[$defaultLanguageId])) {
                $default = $byLanguage[$defaultLanguageId];
            } elseif ($byLanguage !== []) {
                $default = reset($byLanguage);
            }
            $page['title'] = $default;
            $titles = [];
            foreach ($byLanguage as $languageId => $title) {
                $titles[] = ['language_id' => $languageId, 'title' => $title];
            }
            $page['titles'] = $titles;
        }
        unset($page);

        return $pages;
    }
}
