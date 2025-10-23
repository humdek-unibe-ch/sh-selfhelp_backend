<?php

namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Entity\PageVersion;
use App\Entity\User;
use App\Repository\PageRepository;
use App\Repository\PageVersionRepository;
use App\Repository\SectionRepository;
use App\Repository\SectionsFieldsTranslationRepository;
use App\Service\CMS\Frontend\PageService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Core\LookupService;
use App\Service\Auth\UserContextService;
use App\Util\JsonNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Jfcherng\Diff\DiffHelper;

/**
 * PageVersionService
 * 
 * Service for managing page versions and publishing workflow.
 * Handles creating, retrieving, comparing, and managing page versions.
 * 
 * Features:
 * - Create new versions from current page state
 * - Publish/unpublish versions
 * - Version comparison with multiple diff formats
 * - Version history management
 * - Retention policies
 * 
 * @package App\Service\CMS\Admin
 */
class PageVersionService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly PageVersionRepository $pageVersionRepository,
        private readonly PageService $pageService,
        private readonly TransactionService $transactionService,
        private readonly UserContextService $userContextService,
        private readonly SectionRepository $sectionRepository,
        private readonly SectionsFieldsTranslationRepository $translationRepository,
        private readonly SectionUtilityService $sectionUtilityService
    ) {
    }

    /**
     * Create a new version from the current page state
     * 
     * Saves the RAW page structure with ALL languages, conditions, and data_config.
     * Dynamic elements (retrieved_data, condition_debug) are NOT saved.
     * These will be re-run when serving the published version.
     * 
     * @param int $pageId The page ID
     * @param string|null $versionName Optional user-defined name for the version
     * @param array|null $metadata Optional metadata (change summary, tags, etc.)
     * @param int|null $languageId DEPRECATED - not used, all languages are saved
     * @return PageVersion The created version
     * @throws \App\Exception\ServiceException If page not found or version creation fails
     */
    public function createVersion(
        int $pageId,
        ?string $versionName = null,
        ?array $metadata = null,
        ?int $languageId = null
    ): PageVersion {
        try {
            $this->entityManager->beginTransaction();

            // Get the page entity
            $page = $this->pageRepository->find($pageId);
            if (!$page) {
                $this->throwNotFound("Page with ID {$pageId} not found");
            }

            // Get the RAW page structure with ALL languages (not served with dynamic elements)
            $pageJson = $this->getRawPageStructure($pageId);

            // Get the next version number for this page
            $nextVersionNumber = $this->pageVersionRepository->getLatestVersionNumber($pageId) + 1;

            // Get current user - fetch fresh from database to ensure it's managed by EntityManager
            $currentUser = $this->userContextService->getCurrentUser();
            if ($currentUser) {
                // Re-fetch from database to ensure the User is managed by the current EntityManager
                $currentUser = $this->entityManager->find(User::class, $currentUser->getId());
            }

            // Create the PageVersion entity
            $pageVersion = new PageVersion();
            $pageVersion->setPage($page);
            $pageVersion->setVersionNumber($nextVersionNumber);
            $pageVersion->setVersionName($versionName);
            $pageVersion->setPageJson($pageJson);
            $pageVersion->setCreatedBy($currentUser);
            $pageVersion->setMetadata($metadata);

            // Persist the version
            $this->entityManager->persist($pageVersion);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'page_versions',
                $pageVersion->getId(),
                $pageVersion,
                "Created page version {$nextVersionNumber} for page '{$page->getKeyword()}'"
            );

            $this->entityManager->commit();

            return $pageVersion;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new \App\Exception\ServiceException(
                "Failed to create page version: " . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Publish a specific version
     * Sets the version as the currently published version for the page
     * 
     * @param int $pageId The page ID
     * @param int $versionId The version ID to publish
     * @return PageVersion The published version
     * @throws \App\Exception\ServiceException If page or version not found
     */
    public function publishVersion(int $pageId, int $versionId): PageVersion
    {
        try {
            $this->entityManager->beginTransaction();

            // Get the page entity
            $page = $this->pageRepository->find($pageId);
            if (!$page) {
                $this->throwNotFound("Page with ID {$pageId} not found");
            }

            // Get the version entity
            $version = $this->pageVersionRepository->find($versionId);
            if (!$version) {
                $this->throwNotFound("Version with ID {$versionId} not found");
            }

            // Verify the version belongs to this page
            if ($version->getPage()->getId() !== $pageId) {
                $this->throwBadRequest("Version {$versionId} does not belong to page {$pageId}");
            }

            // Update the version's published_at timestamp
            $version->setPublishedAt(new \DateTime());

            // Update the page's published version
            $page->setPublishedVersion($version);

            $this->entityManager->persist($version);
            $this->entityManager->persist($page);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                $page,
                "Published version {$version->getVersionNumber()} for page '{$page->getKeyword()}'"
            );

            $this->entityManager->commit();

            return $version;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new \App\Exception\ServiceException(
                "Failed to publish version: " . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Create and publish a new version in one operation
     * 
     * @param int $pageId The page ID
     * @param string|null $versionName Optional user-defined name for the version
     * @param array|null $metadata Optional metadata
     * @param int|null $languageId Language ID for page data retrieval
     * @return PageVersion The created and published version
     */
    public function createAndPublishVersion(
        int $pageId,
        ?string $versionName = null,
        ?array $metadata = null,
        ?int $languageId = null
    ): PageVersion {
        // Create the version
        $version = $this->createVersion($pageId, $versionName, $metadata, $languageId);

        // Publish it
        return $this->publishVersion($pageId, $version->getId());
    }

    /**
     * Unpublish the current version (revert to draft mode)
     * 
     * @param int $pageId The page ID
     * @return void
     * @throws \App\Exception\ServiceException If page not found
     */
    public function unpublishPage(int $pageId): void
    {
        try {
            $this->entityManager->beginTransaction();

            // Get the page entity
            $page = $this->pageRepository->find($pageId);
            if (!$page) {
                $this->throwNotFound("Page with ID {$pageId} not found");
            }

            // Remove the published version reference
            $page->setPublishedVersion(null);

            $this->entityManager->persist($page);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $pageId,
                $page,
                "Unpublished page '{$page->getKeyword()}' - reverted to draft mode"
            );

            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new \App\Exception\ServiceException(
                "Failed to unpublish page: " . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get the currently published version for a page
     * 
     * @param int $pageId The page ID
     * @return PageVersion|null The published version or null if no version is published
     */
    public function getPublishedVersion(int $pageId): ?PageVersion
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound("Page with ID {$pageId} not found");
        }

        $publishedVersionId = $page->getPublishedVersionId();
        if (!$publishedVersionId) {
            return null;
        }

        return $this->pageVersionRepository->find($publishedVersionId);
    }

    /**
     * Get a specific version by ID
     * 
     * @param int $versionId The version ID
     * @return PageVersion The version
     * @throws \App\Exception\ServiceException If version not found
     */
    public function getVersionById(int $versionId): PageVersion
    {
        $version = $this->pageVersionRepository->find($versionId);
        if (!$version) {
            $this->throwNotFound("Version with ID {$versionId} not found");
        }

        return $version;
    }

    /**
     * Get all versions for a page
     * 
     * @param int $pageId The page ID
     * @return PageVersion[] Array of versions
     */
    public function getPageVersions(int $pageId): array
    {
        return $this->pageVersionRepository->findByPage($pageId);
    }

    /**
     * Get version history with pagination
     * 
     * Includes a fast check to detect if the current draft has unpublished changes
     * compared to the published version using MD5 hash comparison.
     * 
     * @param int $pageId The page ID
     * @param int $limit Maximum number of versions to return
     * @param int $offset Offset for pagination
     * @return array Array containing versions, total count, and unpublished changes flag
     */
    public function getVersionHistory(int $pageId, int $limit = 10, int $offset = 0): array
    {
        $versions = $this->pageVersionRepository->getVersionHistory($pageId, $limit, $offset);
        $totalCount = $this->pageVersionRepository->countVersionsByPage($pageId);

        // Fast check: Does the draft have unpublished changes?
        $hasUnpublishedChanges = $this->hasUnpublishedChanges($pageId);

        return [
            'versions' => $versions,
            'total_count' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_unpublished_changes' => $hasUnpublishedChanges
        ];
    }

    /**
     * Fast check to determine if current draft has unpublished changes
     * 
     * Uses MD5 hash comparison of normalized JSON structures for speed.
     * This is much faster than full diff comparison (typically < 50ms).
     * 
     * @param int $pageId The page ID
     * @return bool True if draft differs from published version, false otherwise
     */
    public function hasUnpublishedChanges(int $pageId): bool
    {
        try {
            // Get the published version
            $publishedVersion = $this->getPublishedVersion($pageId);
            
            // If no published version exists, there are always "unpublished changes"
            if (!$publishedVersion) {
                return true;
            }

            // Get current draft structure
            $draftJson = $this->getRawPageStructure($pageId);
            
            // Get published version structure
            $publishedJson = $publishedVersion->getPageJson();
            
            // Generate normalized hashes for comparison
            $draftHash = $this->generateStructureHash($draftJson);
            $publishedHash = $this->generateStructureHash($publishedJson);
            
            // If hashes differ, there are unpublished changes
            return $draftHash !== $publishedHash;
        } catch (\Exception $e) {
            // If any error occurs, assume there are changes (fail-safe)
            return true;
        }
    }

    /**
     * Generate a fast hash of page structure for comparison
     *
     * Normalizes the JSON structure to ensure consistent hashing:
     * - Sorts arrays by keys recursively (handles property order differences)
     * - Consistent formatting without whitespace
     * - Uses MD5 for speed (we only need equality check, not security)
     *
     * @param array $pageStructure The page JSON structure
     * @return string MD5 hash of normalized structure
     */
    private function generateStructureHash(array $pageStructure): string
    {
        // Use JsonNormalizer to sort keys consistently before hashing
        // This ensures identical content with different property orders hash the same
        $normalizedJson = JsonNormalizer::normalize($pageStructure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Use MD5 for speed (collision resistance not needed for this use case)
        return md5($normalizedJson);
    }

    /**
     * Compare two versions using php-diff library
     * 
     * Supports multiple diff formats:
     * - unified: Unified diff format (default)
     * - side_by_side: Side-by-side HTML comparison
     * - json_patch: JSON Patch (RFC 6902) format
     * - summary: High-level summary of changes
     * 
     * @param int $versionId1 First version ID
     * @param int $versionId2 Second version ID
     * @param string $format Diff format (unified, side_by_side, json_patch, summary)
     * @return array Comparison result
     * @throws \App\Exception\ServiceException If versions not found
     */
    public function compareVersions(int $versionId1, int $versionId2, string $format = 'unified'): array
    {
        // Get both versions
        $version1 = $this->getVersionById($versionId1);
        $version2 = $this->getVersionById($versionId2);

        // Verify both versions belong to the same page
        if ($version1->getPage()->getId() !== $version2->getPage()->getId()) {
            $this->throwBadRequest("Versions must belong to the same page");
        }

        // Get the JSON data for both versions
        $json1 = $version1->getPageJson();
        $json2 = $version2->getPageJson();

        // Normalize the JSON structures
        $normalized1 = JsonNormalizer::normalize($json1);
        $normalized2 = JsonNormalizer::normalize($json2);

        $result = [
            'version1' => [
                'id' => $version1->getId(),
                'version_number' => $version1->getVersionNumber(),
                'version_name' => $version1->getVersionName(),
                'created_at' => $version1->getCreatedAt()->format('Y-m-d H:i:s')
            ],
            'version2' => [
                'id' => $version2->getId(),
                'version_number' => $version2->getVersionNumber(),
                'version_name' => $version2->getVersionName(),
                'created_at' => $version2->getCreatedAt()->format('Y-m-d H:i:s')
            ],
            'format' => $format
        ];

        switch ($format) {
            case 'side_by_side':
                $result['diff'] = DiffHelper::calculate(
                    $normalized1,
                    $normalized2,
                    'SideBySide',
                    ['detailLevel' => 'word']
                );
                break;

            case 'json_patch':
                $result['diff'] = $this->createJsonPatch($json1, $json2);
                break;

            case 'summary':
                $result['diff'] = JsonNormalizer::getDifferenceSummary($json1, $json2);
                break;

            case 'unified':
            default:
                $result['diff'] = DiffHelper::calculate(
                    $normalized1,
                    $normalized2,
                    'Unified',
                    ['detailLevel' => 'line']
                );
                break;
        }

        return $result;
    }

    /**
     * Compare current draft page with a specific version
     * 
     * This allows comparing the current unsaved draft state with a published version
     * to show what changes have been made since the last publish.
     * 
     * @param int $pageId The page ID
     * @param int $versionId The version ID to compare against
     * @param string $format Diff format (unified, side_by_side, json_patch, summary)
     * @return array Comparison result with draft and version data
     * @throws \App\Exception\ServiceException If page or version not found
     */
    public function compareDraftWithVersion(int $pageId, int $versionId, string $format = 'side_by_side'): array
    {
        // Get the current draft page structure
        $draftJson = $this->getRawPageStructure($pageId);

        // Get the version to compare against
        $version = $this->getVersionById($versionId);

        // Verify the version belongs to this page
        if ($version->getPage()->getId() !== $pageId) {
            $this->throwBadRequest("Version {$versionId} does not belong to page {$pageId}");
        }

        $versionJson = $version->getPageJson();

        // Normalize the JSON structures for comparison (sort keys to handle property order differences)
        $normalizedDraft = JsonNormalizer::normalize($draftJson, JSON_PRETTY_PRINT);
        $normalizedVersion = JsonNormalizer::normalize($versionJson, JSON_PRETTY_PRINT);

        $result = [
            'draft' => [
                'id_pages' => $pageId,
                'keyword' => $draftJson['page']['keyword'] ?? null,
                'url' => $draftJson['page']['url'] ?? null,
                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s')
            ],
            'published_version' => [
                'id' => $version->getId(),
                'version_number' => $version->getVersionNumber(),
                'version_name' => $version->getVersionName(),
                'published_at' => $version->getPublishedAt() ? $version->getPublishedAt()->format('Y-m-d H:i:s') : null
            ],
            'format' => $format
        ];

        // Generate diff based on format
        switch ($format) {
            case 'side_by_side':
                $result['diff'] = DiffHelper::calculate(
                    $normalizedVersion,
                    $normalizedDraft,
                    'SideBySide',
                    ['detailLevel' => 'word']
                );
                break;

            case 'json_patch':
                $result['diff'] = $this->createJsonPatch($versionJson, $draftJson);
                break;

            case 'summary':
                $result['diff'] = JsonNormalizer::getDifferenceSummary($versionJson, $draftJson);
                break;

            case 'unified':
            default:
                $result['diff'] = DiffHelper::calculate(
                    $normalizedVersion,
                    $normalizedDraft,
                    'Unified',
                    ['detailLevel' => 'line']
                );
                break;
        }

        return $result;
    }

    /**
     * Create JSON Patch (RFC 6902) from two JSON structures
     * 
     * @param array $json1 First JSON structure
     * @param array $json2 Second JSON structure
     * @return array JSON Patch operations
     */
    private function createJsonPatch(array $json1, array $json2): array
    {
        $operations = [];
        $changes = JsonNormalizer::getDifferenceSummary($json1, $json2)['changes'];

        foreach ($changes as $change) {
            $path = '/' . str_replace('.', '/', $change['path']);

            switch ($change['type']) {
                case 'addition':
                    $operations[] = [
                        'op' => 'add',
                        'path' => $path,
                        'value' => $change['value']
                    ];
                    break;

                case 'removal':
                    $operations[] = [
                        'op' => 'remove',
                        'path' => $path
                    ];
                    break;

                case 'value_change':
                    $operations[] = [
                        'op' => 'replace',
                        'path' => $path,
                        'value' => $change['new_value']
                    ];
                    break;
            }
        }

        return $operations;
    }

    /**
     * Delete a version (hard delete)
     * Note: Cannot delete a currently published version
     * 
     * @param int $versionId The version ID
     * @return void
     * @throws \App\Exception\ServiceException If version not found or is currently published
     */
    public function deleteVersion(int $versionId): void
    {
        try {
            $this->entityManager->beginTransaction();

            $version = $this->getVersionById($versionId);

            // Check if this is the currently published version
            $page = $version->getPage();
            if ($page->getPublishedVersionId() === $versionId) {
                $this->throwBadRequest("Cannot delete the currently published version. Unpublish it first.");
            }

            // Log the transaction before deleting
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'page_versions',
                $versionId,
                $version,
                "Deleted version {$version->getVersionNumber()} for page '{$page->getKeyword()}'"
            );

            $this->entityManager->remove($version);
            $this->entityManager->flush();

            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new \App\Exception\ServiceException(
                "Failed to delete version: " . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Apply retention policy: keep only the last N versions for a page
     * 
     * @param int $pageId The page ID
     * @param int $keepCount Number of recent versions to keep
     * @return int Number of versions deleted
     */
    public function applyRetentionPolicy(int $pageId, int $keepCount = 10): int
    {
        // Get the currently published version to protect it
        $publishedVersion = $this->getPublishedVersion($pageId);
        $publishedVersionId = $publishedVersion ? $publishedVersion->getId() : null;

        // Get all versions for this page
        $versions = $this->pageVersionRepository->findByPage($pageId);

        // If we have fewer versions than the keep count, nothing to delete
        if (count($versions) <= $keepCount) {
            return 0;
        }

        // Sort versions by version number descending (most recent first)
        usort($versions, function ($a, $b) {
            return $b->getVersionNumber() <=> $a->getVersionNumber();
        });

        // Keep the most recent N versions and the published version
        $versionsToKeep = array_slice($versions, 0, $keepCount);
        $versionsToDelete = array_slice($versions, $keepCount);

        $deletedCount = 0;

        foreach ($versionsToDelete as $version) {
            // Don't delete the published version
            if ($version->getId() === $publishedVersionId) {
                continue;
            }

            try {
                $this->deleteVersion($version->getId());
                $deletedCount++;
            } catch (\Exception $e) {
                // Log error but continue
                error_log("Failed to delete version {$version->getId()}: " . $e->getMessage());
            }
        }

        return $deletedCount;
    }

    /**
     * Get raw page structure with ALL languages
     *
     * This returns the raw page structure including:
     * - Page metadata
     * - Sections with ALL language translations (not just one)
     * - Conditions and data_config
     * - NO retrieved_data or condition_debug (dynamic elements)
     *
     * @param int $pageId The page ID
     * @return array Raw page structure with all languages
     * @throws \App\Exception\ServiceException If page not found
     */
    private function getRawPageStructure(int $pageId): array
    {
        // Get the page entity
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound("Page with ID {$pageId} not found");
        }

        // Get all sections for this page (flat structure from DB)
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);

        // Build nested hierarchical structure (without applying data) - use language ID 1 for property translations
        $sections = $this->sectionUtilityService->buildNestedSections($flatSections, false, 1);

        // Extract all section IDs
        $sectionIds = $this->sectionUtilityService->extractSectionIds($sections);

        // Get default language ID for fallback translations
        $defaultLanguageId = null;
        try {
            $cmsPreference = $this->entityManager->getRepository(\App\Entity\CmsPreference::class)->findOneBy([]);
            if ($cmsPreference && $cmsPreference->getDefaultLanguage()) {
                $defaultLanguageId = $cmsPreference->getDefaultLanguage()->getId();
            }
        } catch (\Exception $e) {
            // If there's an error getting the default language, continue without fallback
        }

        // Fetch property translations (language ID 1) for fields of type 1
        $propertyTranslations = $this->translationRepository->fetchTranslationsForSections($sectionIds, 1);

        // Fetch default language translations for fallback
        $defaultTranslations = [];
        if ($defaultLanguageId) {
            $defaultTranslations = $this->translationRepository->fetchTranslationsForSections($sectionIds, $defaultLanguageId);
        }

        // Apply property translations and default values (but NOT regular translations yet)
        $this->sectionUtilityService->applySectionTranslations($sections, [], $defaultTranslations, $propertyTranslations);

        // Now fetch ALL language translations for these sections
        $allTranslations = $this->fetchAllLanguageTranslations($sectionIds);

        // Apply ALL language translations to sections (this adds the 'translations' field for multi-language support)
        $this->applyAllLanguageTranslations($sections, $allTranslations);

        // Build the page structure
        $pageData = [
            'page' => [
                'id' => $page->getId(),
                'keyword' => $page->getKeyword(),
                'url' => $page->getUrl(),
                'parent_page_id' => $page->getParentPage()?->getId(),
                'is_headless' => $page->isHeadless(),
                'nav_position' => $page->getNavPosition(),
                'footer_position' => $page->getFooterPosition(),
                'sections' => $sections
            ]
        ];

        return $pageData;
    }

    /**
     * Fetch translations for ALL languages for given section IDs
     * 
     * @param array $sectionIds Array of section IDs
     * @return array Associative array [section_id => [language_id => [field_name => content]]]
     */
    private function fetchAllLanguageTranslations(array $sectionIds): array
    {
        if (empty($sectionIds)) {
            return [];
        }

        // Query to get ALL translations for these sections across ALL languages
        $qb = $this->translationRepository->createQueryBuilder('sft')
            ->select('s.id AS section_id, f.id AS field_id, f.name AS field_name, l.id AS language_id, l.locale AS locale, sft.content, sft.meta')
            ->leftJoin('sft.section', 's')
            ->leftJoin('sft.field', 'f')
            ->leftJoin('sft.language', 'l')
            ->where('s.id IN (:sectionIds)')
            ->setParameter('sectionIds', $sectionIds);

        $results = $qb->getQuery()->getResult();

        // Organize results by section_id -> language_id -> field_name
        $translations = [];
        foreach ($results as $result) {
            $sectionId = $result['section_id'];
            $languageId = $result['language_id'];
            $fieldName = $result['field_name'];

            if (!isset($translations[$sectionId])) {
                $translations[$sectionId] = [];
            }
            if (!isset($translations[$sectionId][$languageId])) {
                $translations[$sectionId][$languageId] = [];
            }

            $translations[$sectionId][$languageId][$fieldName] = [
                'content' => $result['content'],
                'meta' => $result['meta']
            ];
        }

        return $translations;
    }

    /**
     * Apply ALL language translations to sections recursively
     * 
     * Preserves all existing section fields and adds multi-language translations
     * 
     * @param array &$sections Sections array (passed by reference)
     * @param array $translations All language translations [section_id => [language_id => [field_name => content]]]
     */
    private function applyAllLanguageTranslations(array &$sections, array $translations): void
    {
        foreach ($sections as &$section) {
            $sectionId = $section['id'];

            if (isset($translations[$sectionId])) {
                // Store all language translations in a special field
                // This preserves all the original section fields from the database
                $section['translations'] = $translations[$sectionId];
            }

            // Recursively process children
            if (isset($section['children']) && is_array($section['children'])) {
                $this->applyAllLanguageTranslations($section['children'], $translations);
            }
        }
    }

    /**
     * Strip dynamic elements from section recursively
     * 
     * Removes:
     * - retrieved_data
     * - condition_debug
     * 
     * @param array &$section Section array (passed by reference)
     */
    private function stripDynamicElements(array &$section): void
    {
        // Remove dynamic elements
        unset($section['retrieved_data']);
        unset($section['condition_debug']);

        // Recursively process children
        if (isset($section['children']) && is_array($section['children'])) {
            foreach ($section['children'] as &$child) {
                $this->stripDynamicElements($child);
            }
        }
    }
}

