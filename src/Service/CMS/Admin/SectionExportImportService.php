<?php

namespace App\Service\CMS\Admin;

use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\PagesSection;
use App\Entity\SectionsHierarchy;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\Field;
use App\Exception\ServiceException;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Core\LookupService;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Repository\StyleRepository;
use App\Service\Core\UserContextAwareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling section export/import operations
 */
class SectionExportImportService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SectionUtilityService $sectionUtilityService,
        private readonly StyleRepository $styleRepository,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly ACLService $aclService,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly SectionRelationshipService $sectionRelationshipService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly StyleSchemaService $styleSchemaService
    ) {
    }

    /**
     * Export all sections of a given page (including all nested sections) as JSON
     * 
     * @param int $pageId The ID of the page to export sections from
     * @return array JSON-serializable array with all page sections
     * @throws ServiceException If page not found or access denied
     */
    public function exportPageSections(int $pageId): array
    {
        // Permission check
       $this->userContextAwareService->checkAdminAccessById($pageId, 'select');
        
        // Get the page
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        
        // Use existing hierarchical fetching method
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page->getId());
        
        if (empty($flatSections)) {
            return [];
        }
        
        // Build hierarchical structure using existing utility method
        $hierarchicalSections = $this->sectionUtilityService->buildNestedSections($flatSections,false);
        
        // Add field translations to the hierarchical structure
        $this->addFieldTranslationsToSections($hierarchicalSections);
        
        return $hierarchicalSections;
    }
    
    /**
     * Export a selected section (and all of its nested children) as JSON
     * 
     * @param int $pageId The ID of the page containing the section
     * @param int $sectionId The ID of the section to export
     * @return array JSON-serializable array with the section and its children
     * @throws ServiceException If section not found or access denied
     */
    public function exportSection(int $pageId, int $sectionId): array
    {
        // Permission check
       $this->userContextAwareService->checkAdminAccessById($pageId, 'select');
        $this->sectionRelationshipService->checkSectionInPage($pageId, $sectionId);
        
        // Get the section
        $section = $this->sectionRepository->find($sectionId);
        if (!$section) {
            $this->throwNotFound('Section not found');
        }
        
        // Get the page to use existing hierarchical method
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        
        // Get all sections for the page using existing method
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page->getId());
        
        // Build hierarchical structure
        $hierarchicalSections = $this->sectionUtilityService->buildNestedSections($flatSections,false);
        
        // Find the specific section and its subtree
        $targetSection = $this->findSectionInHierarchy($hierarchicalSections, $sectionId);
        
        if (!$targetSection) {
            $this->throwNotFound('Section not found in page hierarchy');
        }
        
        // Add field translations to the section subtree
        $targetSections = [$targetSection];
        $this->addFieldTranslationsToSections($targetSections);
        
        return $targetSections;
    }
    
    /**
     * Import sections from JSON into a target page
     * 
     * @param int $pageId The ID of the target page
     * @param array $sectionsData The sections data to import
     * @param int|null $position The position where the sections should be inserted
     * @return array Result of the import operation
     * @throws ServiceException If page not found or access denied
     */
    public function importSectionsToPage(int $pageId, array $sectionsData, ?int $position = null): array
    {
        // Permission check
       $this->userContextAwareService->checkAdminAccessById($pageId, 'update');
        
        // Get the page
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        // Phase 1: Dry-run validation — collect ALL issues, abort the request if any were found.
        $this->assertImportPayloadIsValid($sectionsData);

        // Start transaction
        $this->entityManager->beginTransaction();
        
        try {
            $importedSections = $this->importSections($sectionsData, $page, null, $position);

            $this->invalidateImportCaches($page->getId());

            // Commit transaction
            $this->entityManager->commit();
            
            return $importedSections;
        } catch (\Throwable $e) {
            // Rollback transaction
            $this->entityManager->rollback();
            
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to import sections: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }
    
    /**
     * Import sections from JSON into a specific section
     * 
     * @param int $pageId The ID of the target page
     * @param int $parentSectionId The ID of the parent section to import into
     * @param array $sectionsData The sections data to import
     * @param int|null $position The position where the sections should be inserted
     * @return array Result of the import operation
     * @throws ServiceException If section not found or access denied
     */
    public function importSectionsToSection(int $pageId, int $parentSectionId, array $sectionsData, ?int $position = null): array
    {
        // Permission check
       $this->userContextAwareService->checkAdminAccessById($pageId, 'update');
        $this->sectionRelationshipService->checkSectionInPage($pageId, $parentSectionId);
        
        // Get the parent section
        $parentSection = $this->sectionRepository->find($parentSectionId);
        if (!$parentSection) {
            $this->throwNotFound('Parent section not found');
        }

        // Phase 1: Dry-run validation — collect ALL issues, abort the request if any were found.
        $this->assertImportPayloadIsValid($sectionsData);

        // Start transaction
        $this->entityManager->beginTransaction();
        
        try {
            $importedSections = $this->importSections($sectionsData, null, $parentSection, $position);

            $this->invalidateImportCaches($pageId);

            // Commit transaction
            $this->entityManager->commit();
            
            return $importedSections;
        } catch (\Throwable $e) {
            // Rollback transaction
            $this->entityManager->rollback();
            
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to import sections: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Invalidate the cache categories that import / restore can affect.
     *
     * - PAGES: scoped to the affected page (its sections list / page payload).
     * - SECTIONS: every list — imported / restored sections can land anywhere in
     *   the hierarchy and we don't track per-list keys.
     * - CONDITIONS: imported sections can carry `global_fields.condition`, so
     *   condition-cache entries for the page must be dropped too.
     *
     * Kept centralized so the three import paths
     * ({@see importSectionsToPage()}, {@see importSectionsToSection()},
     * {@see restoreSectionsFromVersion()}) stay in sync as cache categories evolve.
     */
    private function invalidateImportCaches(int $pageId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->invalidateAllListsInCategory();
    }

    /**
     * Find a section in hierarchical structure recursively
     * 
     * @param array $sections Hierarchical sections array
     * @param int $sectionId The section ID to find
     * @return array|null The found section with its children, or null if not found
     */
    private function findSectionInHierarchy(array $sections, int $sectionId): ?array
    {
        foreach ($sections as $section) {
            if ($section['id'] == $sectionId) {
                return $section;
            }
            
            // Search in children recursively
            if (!empty($section['children'])) {
                $found = $this->findSectionInHierarchy($section['children'], $sectionId);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Add field translations to sections recursively and build the minimized export shape.
     *
     * Rules (see `docs/section-export-import.md`):
     * - A field entry is emitted only when `content !== styles_fields.default_value` OR `meta !== null`.
     * - `meta` is omitted from the entry when null.
     * - `global_fields` keys are omitted when null; `debug` is omitted when false.
     *   The entire `global_fields` object is omitted when all keys would be omitted.
     * - `children` is omitted when empty.
     * - `fields` is omitted when empty (NOT emitted as `(object)[]` anymore).
     *
     * @param array<int, array<string, mixed>> $sections Hierarchical sections (by reference; rewritten)
     */
    private function addFieldTranslationsToSections(array &$sections): void
    {
        $defaultsByStyle = $this->styleSchemaService->getDefaultValuesByStyleName();

        foreach ($sections as &$section) {
            $sectionId = $section['id'] ?? null;
            if (!$sectionId) {
                continue;
            }

            $sectionEntity = $this->sectionRepository->find($sectionId);
            $styleName = $section['style_name'] ?? null;
            $styleDefaults = $styleName && isset($defaultsByStyle[$styleName])
                ? $defaultsByStyle[$styleName]
                : [];

            $cleanSection = [
                'section_name' => $section['section_name'] ?? '',
                'style_name' => $styleName,
            ];

            // --- fields (translations) ---
            $translations = $this->entityManager->getRepository(SectionsFieldsTranslation::class)
                ->createQueryBuilder('t')
                ->leftJoin('t.field', 'f')
                ->leftJoin('t.language', 'l')
                ->where('t.section = :sectionId')
                ->setParameter('sectionId', $sectionId)
                ->getQuery()
                ->getResult();

            $fields = [];
            foreach ($translations as $translation) {
                $field = $translation->getField();
                $language = $translation->getLanguage();
                if (!$field || !$language) {
                    continue;
                }

                $fieldName = $field->getName();
                $locale = $language->getLocale();
                $content = $translation->getContent();
                $meta = $translation->getMeta();
                $default = $styleDefaults[$fieldName] ?? null;

                // Minimize: skip the entry when it matches the DB default AND has no meta.
                if ($this->isSameAsDefault($content, $default) && ($meta === null || $meta === '')) {
                    continue;
                }

                $entry = ['content' => $content];
                if ($meta !== null && $meta !== '') {
                    $entry['meta'] = $meta;
                }

                if (!isset($fields[$fieldName])) {
                    $fields[$fieldName] = [];
                }
                $fields[$fieldName][$locale] = $entry;
            }

            if (!empty($fields)) {
                $cleanSection['fields'] = $fields;
            }

            // --- global_fields ---
            if ($sectionEntity) {
                $globalFields = [];
                $condition = $sectionEntity->getCondition();
                if ($condition !== null && $condition !== '') {
                    $globalFields['condition'] = $condition;
                }
                $dataConfig = $sectionEntity->getDataConfig();
                if ($dataConfig !== null && $dataConfig !== '') {
                    $globalFields['data_config'] = $dataConfig;
                }
                $css = $sectionEntity->getCss();
                if ($css !== null && $css !== '') {
                    $globalFields['css'] = $css;
                }
                $cssMobile = $sectionEntity->getCssMobile();
                if ($cssMobile !== null && $cssMobile !== '') {
                    $globalFields['css_mobile'] = $cssMobile;
                }
                if ($sectionEntity->isDebug()) {
                    $globalFields['debug'] = true;
                }

                if (!empty($globalFields)) {
                    $cleanSection['global_fields'] = $globalFields;
                }
            }

            // --- children ---
            if (!empty($section['children'])) {
                $this->addFieldTranslationsToSections($section['children']);
                if (!empty($section['children'])) {
                    $cleanSection['children'] = $section['children'];
                }
            }

            $section = $cleanSection;
        }
    }

    /**
     * Strict-as-possible "same as DB default" comparison with tolerance for common storage quirks:
     *  - null ↔ "" ↔ default_value unset
     *  - booleans stored as "0" / "1"
     *  - numbers stored as strings
     */
    private function isSameAsDefault(?string $content, ?string $default): bool
    {
        $normalize = static function (?string $v): string {
            if ($v === null) {
                return '';
            }
            return trim($v);
        };

        return $normalize($content) === $normalize($default);
    }
    
    /**
     * Import sections from JSON data.
     *
     * Always appends a `-{timestamp}` suffix to the section name so concurrent
     * imports can never collide. The `restoreSectionsFromVersion` flow does NOT
     * go through this method — it preserves IDs via `performSmartSectionRestoration`.
     *
     * @param array $sectionsData The sections data to import
     * @param Page|null $page The target page (if importing to page)
     * @param Section|null $parentSection The parent section (if importing to section)
     * @param int|null $globalPosition The global position for the first level of imported sections
     * @return array Result of the import operation
     */
    private function importSections(array $sectionsData, ?Page $page = null, ?Section $parentSection = null, ?int $globalPosition = null): array
    {
        $importedSections = [];
        $currentPosition = $globalPosition;

        foreach ($sectionsData as $index => $sectionData) {
            $section = new Section();

            // Auto-name when `section_name` is omitted — falls back to style name (or `section`) + timestamp.
            $baseName = $sectionData['section_name']
                ?? ($sectionData['style_name'] ?? 'section');
            $sectionName = $baseName . '-' . time();

            $section->setName($sectionName);
            
            // Find style by name
            $styleName = $sectionData['style_name'] ?? null;
            if ($styleName) {
                $style = $this->styleRepository->findOneBy(['name' => $styleName]);
                if ($style) {
                    $section->setStyle($style);
                } else {
                    // Log warning but continue with import
                    $this->transactionService->logTransaction(
                        LookupService::TRANSACTION_TYPES_UPDATE, // Using update type for warnings
                        LookupService::TRANSACTION_BY_BY_USER,
                        'sections',
                        0,
                        (object) ['message' => "Style not found: {$styleName}", 'warning' => true],
                        "Style not found during section import: {$styleName}"
                    );
                }
            }

            // Import global fields if present
            if (isset($sectionData['global_fields']) && is_array($sectionData['global_fields'])) {
                $globalFields = $sectionData['global_fields'];

                if (isset($globalFields['condition'])) {
                    $section->setCondition($globalFields['condition']);
                }
                if (isset($globalFields['data_config'])) {
                    $section->setDataConfig($globalFields['data_config']);
                }
                if (isset($globalFields['css'])) {
                    $section->setCss($globalFields['css']);
                }
                if (isset($globalFields['css_mobile'])) {
                    $section->setCssMobile($globalFields['css_mobile']);
                }
                if (isset($globalFields['debug'])) {
                    $section->setDebug((bool)$globalFields['debug']);
                }
            }

            // Persist section
            $this->entityManager->persist($section);
            $this->entityManager->flush();
            
            // Import fields and translations using new simplified format
            if (isset($sectionData['fields']) && is_array($sectionData['fields']) && !empty($sectionData['fields'])) {
                $this->importSectionFieldsSimplified($section, $sectionData['fields']);
            }
            
            // Determine position for this section
            $sectionPosition = null;
            if ($currentPosition !== null) {
                // Use the global position for the first section, then increment
                $sectionPosition = $currentPosition + $index;
            } else {
                // Use section-specific position if provided, otherwise auto-assign
                $sectionPosition = $sectionData['position'] ?? null;
            }
            
            // Add section to page or parent section
            if ($page) {
                // Add to page
                $pageSection = new PagesSection();
                $pageSection->setPage($page);
                $pageSection->setSection($section);
                
                if ($sectionPosition !== null) {
                    $pageSection->setPosition($sectionPosition);
                } else {
                    // Auto-assign position if not provided
                    $maxPosition = $this->entityManager->createQueryBuilder()
                        ->select('MAX(ps.position)')
                        ->from(PagesSection::class, 'ps')
                        ->where('ps.page = :page')
                        ->setParameter('page', $page)
                        ->getQuery()
                        ->getSingleScalarResult();
                    $pageSection->setPosition(($maxPosition ?? 0) + 1);
                }
                
                $this->entityManager->persist($pageSection);
            } elseif ($parentSection) {
                // Add to parent section
                $sectionHierarchy = new SectionsHierarchy();
                $sectionHierarchy->setParentSection($parentSection);
                $sectionHierarchy->setChildSection($section);
                
                if ($sectionPosition !== null) {
                    $sectionHierarchy->setPosition($sectionPosition);
                } else {
                    // Auto-assign position if not provided
                    $maxPosition = $this->entityManager->createQueryBuilder()
                        ->select('MAX(sh.position)')
                        ->from(SectionsHierarchy::class, 'sh')
                        ->where('sh.parentSection = :parent')
                        ->setParameter('parent', $parentSection)
                        ->getQuery()
                        ->getSingleScalarResult();
                    $sectionHierarchy->setPosition(($maxPosition ?? 0) + 1);
                }
                
                $this->entityManager->persist($sectionHierarchy);
            }
            
            $this->entityManager->flush();
            
            // Record the imported section
            $importedSections[] = [
                'id' => $section->getId(),
                'section_name' => $section->getName(),
                'style_name' => $styleName,
                'position' => $sectionPosition
            ];
            
            // Import child sections recursively if present
            if (isset($sectionData['children']) && is_array($sectionData['children'])) {
                $childResults = $this->importSections($sectionData['children'], null, $section, null);
                $importedSections = array_merge($importedSections, $childResults);
            }
        }

        return $importedSections;
    }

    /**
     * Restore sections from a published version to the current draft
     *
     * This method takes sections from a published version and replaces all current
     * sections on the page with those sections, preserving the original section IDs
     * to maintain referential integrity with dataTables and other relationships.
     *
     * @param int $pageId The ID of the page to restore sections to
     * @param int $versionId The ID of the published version to restore from
     * @return array Result of the restoration operation
     * @throws ServiceException If page/version not found, version not published, or access denied
     */
    public function restoreSectionsFromVersion(int $pageId, int $versionId): array
    {
        // Permission check
        $this->userContextAwareService->checkAdminAccessById($pageId, 'update');

        // Get the page
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        // Get the version
        $version = $this->entityManager->getRepository(\App\Entity\PageVersion::class)->find($versionId);
        if (!$version) {
            $this->throwNotFound('Version not found');
        }

        // Verify the version belongs to this page
        if ($version->getPage()->getId() !== $pageId) {
            $this->throwBadRequest("Version {$versionId} does not belong to page {$pageId}");
        }

        // Verify the version is published
        if (!$version->isPublished()) {
            $this->throwBadRequest("Version {$versionId} is not published. Can only restore from published versions.");
        }

        // Get the sections from the published version
        $pageJson = $version->getPageJson();
        if (!isset($pageJson['page']['sections']) || empty($pageJson['page']['sections'])) {
            $this->throwBadRequest('No sections found in the published version');
        }

        $publishedSections = $pageJson['page']['sections'];

        // Start transaction
        $this->entityManager->beginTransaction();

        try {
            // Step 1: Perform smart restoration that preserves section IDs
            $restorationResult = $this->performSmartSectionRestoration($page, $publishedSections);

            // Step 2: Force flush to ensure all operations are committed to database
            $this->entityManager->flush();

            // Step 3: Invalidate caches (same set as a regular import).
            $this->invalidateImportCaches($page->getId());

            // Step 4: Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                (object) [
                    'id' => $page->getId(),
                    'keyword' => $page->getKeyword(),
                    'url' => $page->getUrl(),
                    'version_restored' => $version->getVersionNumber(),
                    'version_name' => $version->getVersionName(),
                    'sections_updated' => $restorationResult['sections_updated'],
                    'sections_created' => $restorationResult['sections_created'],
                    'sections_deleted' => $restorationResult['sections_deleted']
                ],
                "Restored sections from published version {$version->getVersionNumber()} for page '{$page->getKeyword()}' (preserved IDs)"
            );

            // Commit transaction
            $this->entityManager->commit();

            return [
                'message' => 'Sections successfully restored from published version (IDs preserved)',
                'page_id' => $pageId,
                'version_restored_from' => [
                    'id' => $version->getId(),
                    'version_number' => $version->getVersionNumber(),
                    'version_name' => $version->getVersionName(),
                    'published_at' => $version->getPublishedAt()
                ],
                'sections_updated' => $restorationResult['sections_updated'],
                'sections_created' => $restorationResult['sections_created'],
                'sections_deleted' => $restorationResult['sections_deleted'],
                'sections' => $restorationResult['sections']
            ];
        } catch (\Throwable $e) {
            // Rollback transaction
            $this->entityManager->rollback();

            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to restore sections from version: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Import section fields using simplified format (modular method)
     * Only processes field names with their values - minimal data needed
     *
     * @param Section $section The section to import fields for
     * @param array $fieldsData The simplified fields data to import
     */
    private function importSectionFieldsSimplified(Section $section, array $fieldsData): void
    {
        foreach ($fieldsData as $fieldName => $localeData) {
            // Find field by name. Pre-validation guarantees the field exists and is valid for this style.
            $field = $this->entityManager->getRepository(Field::class)
                ->findOneBy(['name' => $fieldName]);

            if (!$field) {
                // Should be unreachable thanks to assertImportPayloadIsValid(); guard anyway.
                throw new ServiceException(
                    "Unknown field '{$fieldName}' encountered during import (post-validation).",
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Process each locale
            foreach ($localeData as $locale => $translationData) {
                // Find language by locale. Pre-validation guarantees the locale exists.
                $language = $this->entityManager->getRepository(Language::class)
                    ->findOneBy(['locale' => $locale]);

                if (!$language) {
                    throw new ServiceException(
                        "Unknown locale '{$locale}' encountered during import (post-validation).",
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                // `content` is intentionally coerced to '' when `null`. Reasons:
                //   - The DB column `sections_fields_translation.content` is
                //     `TEXT NOT NULL`, and the entity setter takes `string`.
                //   - The export filter (`isSameAsDefault`) already drops
                //     entries that match defaults, so a hand-edited payload
                //     with `content: null` was likely meant as "blank". We
                //     normalize once here instead of forcing every consumer
                //     to handle null specially.
                //   - The JSON schema still allows `["string","null"]` to
                //     stay tolerant of payloads emitted by older exporters.
                $content = $translationData['content'] ?? '';
                $meta = $translationData['meta'] ?? null;

                // Convert meta to JSON string if it's an array or object
                $metaString = null;
                if ($meta !== null) {
                    if (is_array($meta) || is_object($meta)) {
                        $metaString = json_encode($meta);
                    } else {
                        $metaString = (string) $meta;
                    }
                }

                // Check if translation already exists
                $existingTranslation = $this->entityManager->getRepository(SectionsFieldsTranslation::class)
                    ->findOneBy([
                        'section' => $section,
                        'field' => $field,
                        'language' => $language,
                    ]);

                if ($existingTranslation) {
                    // Update existing translation
                    $existingTranslation->setContent($content);
                    $existingTranslation->setMeta($metaString);
                } else {
                    // Create new translation
                    $translation = new SectionsFieldsTranslation();
                    $translation->setSection($section);
                    $translation->setField($field);
                    $translation->setLanguage($language);
                    $translation->setContent($content);
                    $translation->setMeta($metaString);

                    $this->entityManager->persist($translation);
                }
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Perform smart section restoration that preserves section IDs
     *
     * This method restores sections from a published version while preserving
     * the original section IDs to maintain referential integrity with dataTables.
     *
     * @param Page $page The page to restore sections to
     * @param array $publishedSections The sections from the published version
     * @return array Restoration result with statistics
     */
    private function performSmartSectionRestoration(Page $page, array $publishedSections): array
    {
        // Step 1: Flatten published sections for processing
        $flatPublishedSections = $this->flattenPublishedSections($publishedSections);

        // Step 2: Ensure auto-increment is set high enough for manual ID inserts
        $this->ensureAutoIncrementForManualIds($flatPublishedSections);

        // Step 3: Get current sections on the page for comparison
        $currentSectionIds = $this->sectionRepository->getSectionIdsForPage($page->getId());

        // Step 4: Process each section from published version
        $restoredSections = [];
        $sectionsCreated = 0;
        $sectionsUpdated = 0;

        foreach ($flatPublishedSections as $sectionData) {
            $sectionId = $sectionData['id'];

            // Check if section with this ID already exists
            $existingSection = $this->sectionRepository->find($sectionId);

            if ($existingSection) {
                // Update existing section with published data
                $this->updateSectionFromPublishedData($existingSection, $sectionData);
                $sectionsUpdated++;

                // Remove from current sections list (it's being kept)
                $currentSectionIds = array_diff($currentSectionIds, [$sectionId]);
            } else {
                // Create new section with preserved ID
                $newSection = $this->createSectionWithId($sectionData);
                $sectionsCreated++;
            }

            $restoredSections[] = [
                'id' => $sectionId,
                'section_name' => $sectionData['section_name'],
                'action' => $existingSection ? 'updated' : 'created'
            ];
        }

        // Step 5: Remove sections that exist in current page but not in published version
        $sectionsDeleted = $this->removeOrphanedSectionsByIds($currentSectionIds);

        // Step 6: Rebuild section relationships (PagesSection and SectionsHierarchy)
        // Do this last to ensure all sections exist first
        $this->rebuildSectionRelationships($page, $publishedSections);

        return [
            'sections' => $restoredSections,
            'sections_created' => $sectionsCreated,
            'sections_updated' => $sectionsUpdated,
            'sections_deleted' => $sectionsDeleted
        ];
    }

    /**
     * Create a section with a specific ID (preserving from published version)
     *
     * @param array $sectionData Section data from published version
     * @return Section The created section
     */
    private function createSectionWithId(array $sectionData): Section
    {
        try {
            $sectionId = $sectionData['id'];

            // Create new section entity
            $section = new \App\Entity\Section();

            // Set properties first
            $section->setName($sectionData['section_name'] ?? 'Restored Section');

            if ($sectionData['style_name']) {
                $style = $this->styleRepository->findOneBy(['name' => $sectionData['style_name']]);
                if ($style) {
                    $section->setStyle($style);
                }
            }

            $section->setCondition($sectionData['condition'] ?? null);
            $section->setDataConfig($sectionData['data_config'] ?? null);
            $section->setCss($sectionData['css'] ?? null);
            $section->setCssMobile($sectionData['css_mobile'] ?? null);
            $section->setDebug(isset($sectionData['debug']) ? (bool)$sectionData['debug'] : false);

            // Use raw SQL to insert with specific ID (avoid Doctrine auto-generation)
            $conn = $this->entityManager->getConnection();

            $styleId = $section->getStyle() ? $section->getStyle()->getId() : null;

            $sql = "
                INSERT INTO sections (
                    id, name, id_styles, `condition`, data_config, css, css_mobile, debug, timestamp
                ) VALUES (
                    :id, :name, :style_id, :condition, :data_config, :css, :css_mobile, :debug, :timestamp
                )
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute([
                'id' => $sectionId,
                'name' => $section->getName(),
                'style_id' => $styleId,
                'condition' => $section->getCondition(),
                'data_config' => $section->getDataConfig() ? json_encode($section->getDataConfig()) : null,
                'css' => $section->getCss(),
                'css_mobile' => $section->getCssMobile(),
                'debug' => $section->isDebug() ? 1 : 0,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

            // Clear Doctrine's identity map for this entity to avoid conflicts
            $this->entityManager->detach($section);

            // Now retrieve the entity from database
            $section = $this->sectionRepository->find($sectionId);
            if (!$section) {
                throw new \Exception("Failed to retrieve section after creation");
            }

            // Import field translations
            if (isset($sectionData['translations'])) {
                $this->importSectionTranslations($section, $sectionData['translations']);
            }

            return $section;

        } catch (\Throwable $e) {
            // Log the error
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'sections',
                $sectionData['id'] ?? 0,
                (object) [
                    'error' => $e->getMessage(),
                    'section_data' => $sectionData
                ],
                "Failed to create section with ID {$sectionData['id']}: " . $e->getMessage()
            );

            // Re-throw to maintain transaction integrity
            throw $e;
        }
    }

    /**
     * Update an existing section with published data
     *
     * @param Section $section The section to update
     * @param array $sectionData Published section data
     */
    private function updateSectionFromPublishedData(Section $section, array $sectionData): void
    {
        $section->setName($sectionData['section_name'] ?? $section->getName());

        if ($sectionData['style_name']) {
            $style = $this->styleRepository->findOneBy(['name' => $sectionData['style_name']]);
            if ($style) {
                $section->setStyle($style);
            }
        }

        $section->setCondition($sectionData['condition'] ?? null);
        $section->setDataConfig($sectionData['data_config'] ?? null);
        $section->setCss($sectionData['css'] ?? null);
        $section->setCssMobile($sectionData['css_mobile'] ?? null);
        $section->setDebug(isset($sectionData['debug']) ? (bool)$sectionData['debug'] : false);

        // Clear existing translations and import new ones
        $this->clearSectionTranslations($section);
        if (isset($sectionData['translations'])) {
            $this->importSectionTranslations($section, $sectionData['translations']);
        }
    }

    /**
     * Flatten published sections into a flat array with full data
     *
     * @param array $sections Hierarchical sections
     * @param array $parentData Parent section data for hierarchy
     * @return array Flat array of section data
     */
    private function flattenPublishedSections(array $sections, array $parentData = []): array
    {
        $flat = [];

        foreach ($sections as $index => $section) {
            $sectionData = [
                'id' => $section['id'],
                'section_name' => $section['section_name'] ?? '',
                'style_name' => $section['style_name'] ?? null,
                'condition' => $section['condition'] ?? null,
                'data_config' => $section['data_config'] ?? null,
                'css' => $section['css'] ?? null,
                'css_mobile' => $section['css_mobile'] ?? null,
                'debug' => $section['debug'] ?? false,
                'translations' => $section['translations'] ?? [],
                'position' => $section['position'] ?? $index,
                'parent_id' => $parentData['id'] ?? null,
                'parent_position' => $parentData['position'] ?? null
            ];

            $flat[] = $sectionData;

            // Process children recursively
            if (isset($section['children']) && is_array($section['children'])) {
                $childParentData = [
                    'id' => $section['id'],
                    'position' => $sectionData['position']
                ];
                $flat = array_merge($flat, $this->flattenPublishedSections($section['children'], $childParentData));
            }
        }

        return $flat;
    }

    /**
     * Rebuild section relationships (PagesSection and SectionsHierarchy)
     *
     * @param Page $page The page
     * @param array $publishedSections The hierarchical sections structure
     */
    private function rebuildSectionRelationships(Page $page, array $publishedSections): void
    {
        // Get all section IDs that will be involved in the restoration
        $flatPublishedSections = $this->flattenPublishedSections($publishedSections);
        $sectionIds = array_column($flatPublishedSections, 'id');

        // Aggressively clear ALL existing relationships for these sections
        if (!empty($sectionIds)) {
            try {
                // Clear ALL PagesSection relationships for these sections (across all pages)
                $deletedPagesSections = $this->entityManager->createQueryBuilder()
                    ->delete(\App\Entity\PagesSection::class, 'ps')
                    ->where('ps.section IN (:sectionIds)')
                    ->setParameter('sectionIds', $sectionIds)
                    ->getQuery()
                    ->execute();

                // Clear ALL SectionsHierarchy relationships for these sections
                $deletedHierarchy = $this->entityManager->createQueryBuilder()
                    ->delete(\App\Entity\SectionsHierarchy::class, 'sh')
                    ->where('sh.parentSection IN (:sectionIds) OR sh.childSection IN (:sectionIds)')
                    ->setParameter('sectionIds', $sectionIds)
                    ->getQuery()
                    ->execute();

                // Log the clearing operation
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_SYSTEM,
                    'relationships',
                    0,
                    (object) [
                        'page_id' => $page->getId(),
                        'sections_cleared' => count($sectionIds),
                        'pages_sections_deleted' => $deletedPagesSections,
                        'hierarchy_deleted' => $deletedHierarchy
                    ],
                    "Cleared relationships for section restoration on page {$page->getId()}"
                );

            } catch (\Exception $e) {
                // Log the Doctrine failure
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_SYSTEM,
                    'relationships',
                    0,
                    (object) [
                        'error' => 'Doctrine clearing failed: ' . $e->getMessage(),
                        'page_id' => $page->getId(),
                        'fallback' => 'raw_sql'
                    ],
                    "Doctrine relationship clearing failed, using raw SQL fallback"
                );

                // If Doctrine queries fail, try raw SQL
                $conn = $this->entityManager->getConnection();
                $idsString = implode(',', $sectionIds);

                $conn->executeStatement("DELETE FROM pages_sections WHERE id_pages = {$page->getId()}");
                $conn->executeStatement("DELETE FROM sections_hierarchy WHERE parent IN ({$idsString}) OR child IN ({$idsString})");
            }
        }

        // Rebuild relationships from published structure
        $this->rebuildRelationshipsRecursive($page, $publishedSections, null, null);
    }

    /**
     * Recursively rebuild section relationships
     *
     * @param Page $page The page
     * @param array $sections Sections array
     * @param Section|null $parentSection Parent section (null for root level)
     * @param int|null $parentPosition Parent position
     */
    private function rebuildRelationshipsRecursive(Page $page, array $sections, ?Section $parentSection, ?int $parentPosition): void
    {
        foreach ($sections as $position => $sectionData) {
            $section = $this->sectionRepository->find($sectionData['id']);

            if ($section) {
                try {
                    if ($page && !$parentSection) {
                        // Root level - add to page
                        $pageSection = new \App\Entity\PagesSection();
                        $pageSection->setPage($page);
                        $pageSection->setSection($section);
                        $pageSection->setPosition($position);
                        $this->entityManager->persist($pageSection);
                    } elseif ($parentSection) {
                        // Child level - add to parent
                        $sectionHierarchy = new \App\Entity\SectionsHierarchy();
                        $sectionHierarchy->setParentSection($parentSection);
                        $sectionHierarchy->setChildSection($section);
                        $sectionHierarchy->setPosition($position);
                        $this->entityManager->persist($sectionHierarchy);
                    }

                    // Process children
                    if (isset($sectionData['children']) && is_array($sectionData['children'])) {
                        $this->rebuildRelationshipsRecursive($page, $sectionData['children'], $section, $position);
                    }
                } catch (\Exception $e) {
                    // Log relationship creation failure
                    $this->transactionService->logTransaction(
                        LookupService::TRANSACTION_TYPES_UPDATE,
                        LookupService::TRANSACTION_BY_BY_SYSTEM,
                        'relationships',
                        0,
                        (object) [
                            'error' => $e->getMessage(),
                            'section_id' => $sectionData['id'],
                            'parent_id' => $parentSection ? $parentSection->getId() : null,
                            'position' => $position
                        ],
                        "Failed to create relationship for section {$sectionData['id']}: " . $e->getMessage()
                    );

                    // Continue with other sections instead of failing completely
                    continue;
                }
            } else {
                // Log missing section
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_SYSTEM,
                    'relationships',
                    0,
                    (object) [
                        'warning' => 'Section not found',
                        'section_id' => $sectionData['id'],
                        'page_id' => $page->getId()
                    ],
                    "Section {$sectionData['id']} not found during relationship rebuilding"
                );
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log flush failure
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'relationships',
                0,
                (object) [
                    'error' => 'Flush failed: ' . $e->getMessage(),
                    'page_id' => $page->getId()
                ],
                "Failed to flush relationships during restoration"
            );

            // Don't re-throw, let the transaction continue
        }
    }

    /**
     * Remove sections by their IDs
     *
     * @param array $sectionIds Array of section IDs to delete
     * @return int Number of sections deleted
     */
    private function removeOrphanedSectionsByIds(array $sectionIds): int
    {
        if (empty($sectionIds)) {
            return 0;
        }

        $deletedCount = 0;
        foreach ($sectionIds as $sectionId) {
            $section = $this->sectionRepository->find($sectionId);
            if (!$section) {
                continue;
            }

            // Delete relationships first
            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\PagesSection::class, 'ps')
                ->where('ps.section = :section')
                ->setParameter('section', $section)
                ->getQuery()
                ->execute();

            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\SectionsHierarchy::class, 'sh')
                ->where('sh.parentSection = :section OR sh.childSection = :section')
                ->setParameter('section', $section)
                ->getQuery()
                ->execute();

            // Delete translations
            $this->entityManager->createQueryBuilder()
                ->delete(\App\Entity\SectionsFieldsTranslation::class, 'sft')
                ->where('sft.section = :section')
                ->setParameter('section', $section)
                ->getQuery()
                ->execute();

            // Delete the section
            $this->entityManager->remove($section);
            $deletedCount++;
        }

        $this->entityManager->flush();
        return $deletedCount;
    }

    /**
     * Import translations for a section
     *
     * @param Section $section The section
     * @param array $translations Translations data
     */
    private function importSectionTranslations(Section $section, array $translations): void
    {
        foreach ($translations as $languageId => $languageTranslations) {
            $language = $this->entityManager->getRepository(\App\Entity\Language::class)->find($languageId);
            if (!$language) continue;

            foreach ($languageTranslations as $fieldName => $fieldData) {
                $field = $this->entityManager->getRepository(\App\Entity\Field::class)
                    ->findOneBy(['name' => $fieldName]);

                if ($field) {
                    $translation = new \App\Entity\SectionsFieldsTranslation();
                    $translation->setSection($section);
                    $translation->setField($field);
                    $translation->setLanguage($language);
                    $translation->setContent($fieldData['content'] ?? '');
                    $translation->setMeta($fieldData['meta'] ?? null);

                    $this->entityManager->persist($translation);
                }
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Ensure auto-increment value is set high enough to avoid conflicts with manual ID inserts
     *
     * @param array $sectionDataArray Array of section data
     */
    private function ensureAutoIncrementForManualIds(array $sectionDataArray): void
    {
        if (empty($sectionDataArray)) {
            return;
        }

        // Find the highest ID we need to insert
        $maxId = max(array_column($sectionDataArray, 'id'));

        // Get current auto-increment value
        $conn = $this->entityManager->getConnection();
        $result = $conn->executeQuery("SHOW TABLE STATUS LIKE 'sections'");
        $tableStatus = $result->fetchAssociative();

        $currentAutoIncrement = $tableStatus['Auto_increment'] ?? 1;

        // If our max ID is higher than current auto-increment, update it
        if ($maxId >= $currentAutoIncrement) {
            $newAutoIncrement = $maxId + 1;
            $conn->executeStatement("ALTER TABLE sections AUTO_INCREMENT = {$newAutoIncrement}");
        }
    }

    /**
     * Clear all translations for a section
     *
     * @param Section $section The section
     */
    private function clearSectionTranslations(Section $section): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(\App\Entity\SectionsFieldsTranslation::class, 'sft')
            ->where('sft.section = :section')
            ->setParameter('section', $section)
            ->getQuery()
            ->execute();
    }

    /**
     * Two-phase pre-validation entry point.
     *
     * Walks the entire import payload, collects EVERY issue into an
     * array, and throws a single ServiceException (HTTP 422) when any
     * issue is found — so the FE can display all errors at once.
     *
     * Detected issues:
     *  - unknown style_name
     *  - field that doesn't exist in the `fields` table
     *  - field that isn't part of the `styles_fields` mapping for the section's style
     *  - unknown locale (no row in `languages.locale`)
     *  - missing `style_name` (required)
     *
     * @param array<int, array<string, mixed>> $sectionsData Import tree as received by the controller
     * @throws ServiceException HTTP 422 with `errors` array in ServiceException::getData()
     */
    private function assertImportPayloadIsValid(array $sectionsData): void
    {
        $schema = $this->styleSchemaService->getSchema();
        $allFieldNames = $this->getAllFieldNamesMap();
        $allLocales = $this->getAllLocalesMap();

        $errors = [];
        $this->validateSectionsRecursive($sectionsData, '$', $schema, $allFieldNames, $allLocales, $errors);

        if (!empty($errors)) {
            throw new ServiceException(
                'Import validation failed. See `data.errors[]` for per-node details.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['errors' => $errors]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param string $path JSONPath-like prefix used to describe error locations
     * @param array<string, array<string, mixed>> $schema styleName => schema
     * @param array<string, bool> $allFieldNames fast fieldName existence map
     * @param array<string, bool> $allLocales fast locale existence map
     * @param array<int, array<string, string>> &$errors collected errors
     */
    private function validateSectionsRecursive(
        array $sections,
        string $path,
        array $schema,
        array $allFieldNames,
        array $allLocales,
        array &$errors
    ): void {
        foreach ($sections as $index => $sectionData) {
            $sectionPath = $path . '.sections[' . $index . ']';

            $styleName = $sectionData['style_name'] ?? null;
            if ($styleName === null || $styleName === '') {
                $errors[] = [
                    'path' => $sectionPath,
                    'type' => 'missing_style',
                    'detail' => "'style_name' is required for every section entry.",
                ];
                // Without a style we can't validate fields/children — skip the rest of this node.
                continue;
            }

            if (!isset($schema[$styleName])) {
                $errors[] = [
                    'path' => $sectionPath . '.style_name',
                    'type' => 'unknown_style',
                    'detail' => "Style '{$styleName}' is not registered in the CMS.",
                ];
                // Keep scanning children & fields: their own errors are still useful to surface.
            }

            $styleFieldNames = isset($schema[$styleName])
                ? array_flip(array_keys($schema[$styleName]['fields']))
                : [];

            // Validate fields
            $fields = $sectionData['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $fieldName => $localeData) {
                    $fieldPath = $sectionPath . '.fields.' . $fieldName;

                    if (!isset($allFieldNames[$fieldName])) {
                        $errors[] = [
                            'path' => $fieldPath,
                            'type' => 'unknown_field',
                            'detail' => "Field '{$fieldName}' does not exist.",
                        ];
                        continue;
                    }

                    if (isset($schema[$styleName]) && !isset($styleFieldNames[$fieldName])) {
                        $errors[] = [
                            'path' => $fieldPath,
                            'type' => 'invalid_field_for_style',
                            'detail' => "Field '{$fieldName}' is not valid for style '{$styleName}'.",
                        ];
                        continue;
                    }

                    if (!is_array($localeData)) {
                        $errors[] = [
                            'path' => $fieldPath,
                            'type' => 'invalid_field_shape',
                            'detail' => "Field '{$fieldName}' must be an object keyed by locale.",
                        ];
                        continue;
                    }

                    foreach ($localeData as $locale => $translation) {
                        if (!is_string($locale) || $locale === '' || !isset($allLocales[$locale])) {
                            $errors[] = [
                                'path' => $fieldPath . '.' . (is_string($locale) ? $locale : '?'),
                                'type' => 'unknown_locale',
                                'detail' => "Locale '" . (is_string($locale) ? $locale : '?') . "' is not registered.",
                            ];
                            continue;
                        }

                        if (!is_array($translation) || !array_key_exists('content', $translation)) {
                            $errors[] = [
                                'path' => $fieldPath . '.' . $locale,
                                'type' => 'missing_content',
                                'detail' => "Translation entry must include a 'content' key.",
                            ];
                        }
                    }
                }
            }

            // Recurse into children
            if (!empty($sectionData['children']) && is_array($sectionData['children'])) {
                $this->validateSectionsRecursive(
                    $sectionData['children'],
                    $sectionPath,
                    $schema,
                    $allFieldNames,
                    $allLocales,
                    $errors
                );
            }
        }
    }

    /**
     * Fast existence map for every registered field name (loaded once per request).
     *
     * @return array<string, bool>
     */
    private function getAllFieldNamesMap(): array
    {
        $conn = $this->entityManager->getConnection();
        $rows = $conn->executeQuery('SELECT name FROM fields')->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['name']] = true;
        }
        return $map;
    }

    /**
     * Fast existence map for every registered locale (loaded once per request).
     *
     * @return array<string, bool>
     */
    private function getAllLocalesMap(): array
    {
        $conn = $this->entityManager->getConnection();
        $rows = $conn->executeQuery('SELECT locale FROM languages')->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['locale']] = true;
        }
        return $map;
    }
} 