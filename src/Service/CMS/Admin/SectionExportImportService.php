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
use App\Service\Auth\UserContextService;
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
        private readonly UserContextAwareService $userContextAwareService
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
       $this->userContextAwareService->checkAccessById($pageId, 'select');
        
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
       $this->userContextAwareService->checkAccessById($pageId, 'select');
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
       $this->userContextAwareService->checkAccessById($pageId, 'update');
        
        // Get the page
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        
        // Start transaction
        $this->entityManager->beginTransaction();
        
        try {
            $importedSections = $this->importSections($sectionsData, $page, null, $position);
            
            // Invalidate page and sections cache after import
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $page->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();
            
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
       $this->userContextAwareService->checkAccessById($pageId, 'update');
        $this->sectionRelationshipService->checkSectionInPage($pageId, $parentSectionId);
        
        // Get the parent section
        $parentSection = $this->sectionRepository->find($parentSectionId);
        if (!$parentSection) {
            $this->throwNotFound('Parent section not found');
        }
        
        // Start transaction
        $this->entityManager->beginTransaction();
        
        try {
            $importedSections = $this->importSections($sectionsData, null, $parentSection, $position);
            
            // Invalidate sections cache after import
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, $parentSection->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();
            
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
     * Add field translations to sections recursively (modular method)
     * Only exports field names with their values - minimal data needed for import
     * 
     * @param array &$sections Hierarchical sections array (passed by reference)
     */
    private function addFieldTranslationsToSections(array &$sections): void
    {
        foreach ($sections as &$section) {
            $sectionId = $section['id'] ?? null;
            if (!$sectionId) {
                continue;
            }

            // Fetch the Section entity to get global fields
            $sectionEntity = $this->sectionRepository->find($sectionId);

            // Clean up section structure - keep only essential fields
            $cleanSection = [
                'section_name' => $section['section_name'] ?? '',
                'style_name' => $section['style_name'] ?? null,
                'children' => [],
                'fields' => (object)[],
                'global_fields' => [
                    'condition' => $sectionEntity ? $sectionEntity->getCondition() : null,
                    'data_config' => $sectionEntity ? $sectionEntity->getDataConfig() : null,
                    'css' => $sectionEntity ? $sectionEntity->getCss() : null,
                    'css_mobile' => $sectionEntity ? $sectionEntity->getCssMobile() : null,
                    'debug' => $sectionEntity ? $sectionEntity->isDebug() : false,
                ]
            ];
            
            // Get all translations for this section
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
                
                // Initialize field if not exists
                if (!isset($fields[$fieldName])) {
                    $fields[$fieldName] = [];
                }
                
                // Store translation by locale only
                $fields[$fieldName][$locale] = [
                    'content' => $translation->getContent(),
                    'meta' => $translation->getMeta()
                ];
            }
            
            // Add fields to clean section - use object if empty to match JSON schema
            $cleanSection['fields'] = empty($fields) ? (object)[] : $fields;
            
            // Process children recursively
            if (!empty($section['children'])) {
                $this->addFieldTranslationsToSections($section['children']);
                $cleanSection['children'] = $section['children'];
            }
            
            // Replace the section with clean version
            $section = $cleanSection;
        }
    }
    
    /**
     * Import sections from JSON data
     *
     * @param array $sectionsData The sections data to import
     * @param Page|null $page The target page (if importing to page)
     * @param Section|null $parentSection The parent section (if importing to section)
     * @param int|null $globalPosition The global position for the first level of imported sections
     * @param bool $preserveNames Whether to preserve original section names (for restoration) or add timestamps (for import)
     * @return array Result of the import operation
     */
    private function importSections(array $sectionsData, ?Page $page = null, ?Section $parentSection = null, ?int $globalPosition = null, bool $preserveNames = false): array
    {
        $importedSections = [];
        $currentPosition = $globalPosition;
        
        foreach ($sectionsData as $index => $sectionData) {
            // Create new section
            $section = new Section();

            // Set section name - preserve original for restoration, add timestamp for import
            $baseName = $sectionData['section_name'] ?? 'Imported Section';
            $sectionName = $baseName; // Default to original name

            if ($preserveNames) {
                // For restoration: we'll try original name first, but prepare fallback
                $sectionName = $baseName;
            } else {
                // For import: add timestamp suffix to ensure uniqueness
                $timestamp = time();
                $sectionName = $baseName . '-' . $timestamp;
            }

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
                $childResults = $this->importSections($sectionData['children'], null, $section, null, $preserveNames);
                $importedSections = array_merge($importedSections, $childResults);
            }
        }
        
        return $importedSections;
    }

    /**
     * Restore sections from a published version to the current draft
     *
     * This method takes sections from a published version and replaces all current
     * sections on the page with those sections, effectively restoring the page
     * to a previous published state while keeping it as a draft for further editing.
     *
     * @param int $pageId The ID of the page to restore sections to
     * @param int $versionId The ID of the published version to restore from
     * @return array Result of the restoration operation
     * @throws ServiceException If page/version not found, version not published, or access denied
     */
    public function restoreSectionsFromVersion(int $pageId, int $versionId): array
    {
        // Permission check
        $this->userContextAwareService->checkAccessById($pageId, 'update');

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

        // Convert published version sections to export format for import
        $sectionsToRestore = $this->convertPublishedSectionsToExportFormat($pageJson['page']['sections']);

        // Start transaction
        $this->entityManager->beginTransaction();

        try {
            // Step 1: Clear existing sections for the page
            $this->clearPageSections($page);

            // Step 2: Import sections from the published version (preserve original names)
            $importedSections = $this->importSections($sectionsToRestore, $page, null, null, true);

            // Step 3: Invalidate page and sections cache after restoration
            $this->cache
                ->withCategory(CacheService::CATEGORY_PAGES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $page->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->invalidateAllListsInCategory();

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
                    'version_name' => $version->getVersionName()
                ],
                "Restored sections from published version {$version->getVersionNumber()} for page '{$page->getKeyword()}'"
            );

            // Commit transaction
            $this->entityManager->commit();

            return [
                'message' => 'Sections successfully restored from published version',
                'page_id' => $pageId,
                'version_restored_from' => [
                    'id' => $version->getId(),
                    'version_number' => $version->getVersionNumber(),
                    'version_name' => $version->getVersionName(),
                    'published_at' => $version->getPublishedAt()
                ],
                'sections_restored' => count($importedSections),
                'imported_sections' => $importedSections
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
     * Convert published version sections to export format
     *
     * Transforms the complex published version structure with translations
     * into the simpler export format that the import logic expects.
     *
     * @param array $publishedSections The sections from published version JSON
     * @return array Sections in export format
     */
    private function convertPublishedSectionsToExportFormat(array $publishedSections): array
    {
        $exportSections = [];

        foreach ($publishedSections as $section) {
            $exportSection = [
                'section_name' => $section['section_name'] ?? '',
                'style_name' => $section['style_name'] ?? null,
                'children' => [],
                'fields' => (object)[],
                'global_fields' => [
                    'condition' => $section['condition'] ?? null,
                    'data_config' => $section['data_config'] ?? null,
                    'css' => $section['css'] ?? null,
                    'css_mobile' => $section['css_mobile'] ?? null,
                    'debug' => isset($section['debug']) ? (bool)$section['debug'] : false,
                ]
            ];

            $fields = [];

            // Process translations from the translations object first
            // These contain the raw multilingual data
            if (isset($section['translations']) && is_array($section['translations'])) {
                foreach ($section['translations'] as $languageId => $languageTranslations) {
                    // Get locale for this language ID
                    $locale = $this->getLocaleForLanguageId((int)$languageId);
                    if (!$locale) continue;

                    foreach ($languageTranslations as $fieldName => $fieldData) {
                        if (!isset($fields[$fieldName])) {
                            $fields[$fieldName] = [];
                        }
                        $fields[$fieldName][$locale] = [
                            'content' => $fieldData['content'] ?? '',
                            'meta' => $fieldData['meta'] ?? null
                        ];
                    }
                }
            }

            // Process fields that are also at the root level of the section
            // These are fallback values, only use if not already in translations
            foreach ($section as $key => $value) {
                // Skip known non-field keys
                if (in_array($key, [
                    'id', 'css', 'path', 'debug', 'level', 'children', 'position',
                    'condition', 'id_styles', 'css_mobile', 'style_name', 'data_config',
                    'section_name', 'translations', 'can_have_children', 'use_mantine_style',
                    'mantine_spacing_margin_padding'
                ])) {
                    continue;
                }

                // If it's a field (has content/meta structure) and not already processed from translations
                if (is_array($value) && isset($value['content']) && !isset($fields[$key])) {
                    // Get default locale for root-level fields
                    $defaultLocale = $this->getDefaultLocale();
                    if ($defaultLocale) {
                        $fields[$key] = [];
                        $fields[$key][$defaultLocale] = [
                            'content' => $value['content'] ?? '',
                            'meta' => $value['meta'] ?? null
                        ];
                    }
                }
            }

            // Convert fields to object if empty, otherwise keep as array
            $exportSection['fields'] = empty($fields) ? (object)[] : $fields;

            // Process children recursively
            if (isset($section['children']) && is_array($section['children'])) {
                $exportSection['children'] = $this->convertPublishedSectionsToExportFormat($section['children']);
            }

            $exportSections[] = $exportSection;
        }

        return $exportSections;
    }

    /**
     * Get locale for a language ID
     *
     * @param int $languageId
     * @return string|null
     */
    private function getLocaleForLanguageId(int $languageId): ?string
    {
        try {
            $language = $this->entityManager->getRepository(\App\Entity\Language::class)->find($languageId);
            return $language ? $language->getLocale() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the default locale
     *
     * @return string|null
     */
    private function getDefaultLocale(): ?string
    {
        try {
            $cmsPreference = $this->entityManager->getRepository(\App\Entity\CmsPreference::class)->findOneBy([]);
            if ($cmsPreference && $cmsPreference->getDefaultLanguage()) {
                return $cmsPreference->getDefaultLanguage()->getLocale();
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return 'en-GB'; // Fallback
    }

    /**
     * Clear all sections for a given page
     *
     * This removes all sections and their hierarchical relationships from the page,
     * preparing it for restoration from a published version.
     *
     * @param Page $page The page to clear sections from
     */
    private function clearPageSections(Page $page): void
    {
        $pageId = $page->getId();

        // Get all section IDs currently associated with this page
        $sectionIds = $this->sectionRepository->getSectionIdsForPage($pageId);

        if (empty($sectionIds)) {
            return; // No sections to clear
        }

        // Delete section relationships (PagesSection)
        $this->entityManager->createQueryBuilder()
            ->delete(PagesSection::class, 'ps')
            ->where('ps.page = :page')
            ->setParameter('page', $page)
            ->getQuery()
            ->execute();

        // Delete hierarchical relationships (SectionsHierarchy) for these sections
        $this->entityManager->createQueryBuilder()
            ->delete(SectionsHierarchy::class, 'sh')
            ->where('sh.parentSection IN (:sectionIds) OR sh.childSection IN (:sectionIds)')
            ->setParameter('sectionIds', $sectionIds)
            ->getQuery()
            ->execute();

        // Delete field translations for these sections
        $this->entityManager->createQueryBuilder()
            ->delete(SectionsFieldsTranslation::class, 'sft')
            ->where('sft.section IN (:sectionIds)')
            ->setParameter('sectionIds', $sectionIds)
            ->getQuery()
            ->execute();

        // Finally, delete the sections themselves
        $this->entityManager->createQueryBuilder()
            ->delete(Section::class, 's')
            ->where('s.id IN (:sectionIds)')
            ->setParameter('sectionIds', $sectionIds)
            ->getQuery()
            ->execute();

        // Flush to ensure all deletions are committed
        $this->entityManager->flush();
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
            // Find field by name
            $field = $this->entityManager->getRepository(Field::class)
                ->findOneBy(['name' => $fieldName]);

            if (!$field) {
                // Skip fields that don't exist in the system
                continue;
            }

            // Process each locale
            foreach ($localeData as $locale => $translationData) {
                // Find language by locale
                $language = $this->entityManager->getRepository(Language::class)
                    ->findOneBy(['locale' => $locale]);

                if (!$language) {
                    // Skip translations for languages that don't exist
                    continue;
                }

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
} 