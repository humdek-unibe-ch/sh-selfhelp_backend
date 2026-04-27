<?php

namespace App\Service\CMS;

use App\Entity\Page;
use App\Repository\LanguageRepository;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralized service for managing global variables from sh-global-values page
 *
 * This service consolidates all global variable operations to eliminate duplication
 * between DataVariableResolver and VariableResolverService.
 */
class GlobalVariableService extends BaseService
{
    private const SH_GLOBAL_VALUES_KEYWORD = 'sh-global-values';
    private const PF_GLOBAL_VALUES = 'global_values';

    // Request-scoped memoization. Symfony shared services live for a single HTTP request,
    // so these properties act as a zero-overhead in-request cache. Without them the
    // page lookup below ran 200+ times on pages that interpolate many sections, each
    // hitting MySQL round-trip latency. See PageService::processSectionsRecursively.
    private ?Page $memoizedGlobalPage = null;
    private bool $globalPageLoaded = false;
    /** @var array<int, array<string, mixed>> keyed by languageId */
    private array $memoizedGlobalValuesByLanguage = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Get global variable VALUES for a specific language
     *
     * @param int $languageId Language ID to get values for
     * @return array Array of variable names to their values (e.g., ['my_var' => 'english'])
     */
    public function getGlobalVariableValues(int $languageId): array
    {
        if (array_key_exists($languageId, $this->memoizedGlobalValuesByLanguage)) {
            return $this->memoizedGlobalValuesByLanguage[$languageId];
        }

        $cacheKey = "global_variable_values_{$languageId}";
        $globalPage = $this->getGlobalPage();

        if (!$globalPage) {
            return $this->memoizedGlobalValuesByLanguage[$languageId] = [];
        }

        $values = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $globalPage->getId())
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->getItem($cacheKey, function () use ($globalPage, $languageId) {
                $globalValuesJson = $this->getPageFieldValueByLanguage($globalPage, self::PF_GLOBAL_VALUES, $languageId);

                if ($globalValuesJson) {
                    $globalValues = json_decode($globalValuesJson, true);
                    if (is_array($globalValues)) {
                        return $globalValues;
                    }
                }

                return [];
            });

        return $this->memoizedGlobalValuesByLanguage[$languageId] = $values ?? [];
    }

    /**
     * Get global variable NAMES across all languages
     *
     * Returns unique variable names that exist in any language, prefixed with 'global.'
     * Used for admin UI dropdowns and autocomplete.
     *
     * @return array List of global variable names with 'global.' prefix (e.g., ['global.my_var'])
     */
    public function getGlobalVariableNames(): array
    {
        $cacheKey = "global_variable_names_all_languages";
        $globalPage = $this->getGlobalPage();

        // Build cache service with dependencies on global page and all languages
        $cacheService = $this->cache->withCategory(CacheService::CATEGORY_PAGES);

        if ($globalPage) {
            $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $globalPage->getId());
        }

        // Add all languages as dependencies
        $allLanguages = $this->languageRepository->findAllLanguages();
        foreach ($allLanguages as $language) {
            $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $language->getId());
        }

        return $cacheService->getList($cacheKey, function () use ($globalPage, $allLanguages) {
            $variables = [];

            if (!$globalPage) {
                return $variables;
            }

            // Get global variables for each language
            foreach ($allLanguages as $language) {
                $globalValuesJson = $this->getPageFieldValueByLanguage($globalPage, self::PF_GLOBAL_VALUES, $language->getId());
                if ($globalValuesJson) {
                    $globalValues = json_decode($globalValuesJson, true);
                    if (is_array($globalValues)) {
                        foreach (array_keys($globalValues) as $key) {
                            $variables[] = 'globals.' . $key;
                        }
                    }
                }
            }

            // Remove duplicates while preserving order
            return array_unique($variables);
        });
    }

    /**
     * Get the global values page
     *
     * Result is memoized for the lifetime of the request — the same Page entity is
     * requested many times per page render (once per interpolated section), and
     * each findOneBy was issuing a fresh SQL round-trip.
     *
     * @return Page|null The global values page or null if not found
     */
    private function getGlobalPage(): ?Page
    {
        if ($this->globalPageLoaded) {
            return $this->memoizedGlobalPage;
        }

        try {
            $this->memoizedGlobalPage = $this->pageRepository->findOneBy(['keyword' => self::SH_GLOBAL_VALUES_KEYWORD]);
        } catch (\Exception $e) {
            $this->memoizedGlobalPage = null;
        }

        $this->globalPageLoaded = true;
        return $this->memoizedGlobalPage;
    }

    /**
     * Get page field value by field name and language
     *
     * @param Page $page The page entity
     * @param string $fieldName The field name to look for
     * @param int $languageId The language ID to get the translation for
     * @return string|null The field value or null if not found
     */
    private function getPageFieldValueByLanguage(Page $page, string $fieldName, int $languageId): ?string
    {
        try {
            $conn = $this->entityManager->getConnection();
            $sql = "
                SELECT pft.content
                FROM pages_fields_translation pft
                INNER JOIN fields f ON pft.id_fields = f.id
                WHERE pft.id_pages = :pageId
                AND f.name = :fieldName
                AND pft.id_languages = :languageId
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('pageId', $page->getId(), \Doctrine\DBAL\ParameterType::INTEGER);
            $stmt->bindValue('fieldName', $fieldName, \Doctrine\DBAL\ParameterType::STRING);
            $stmt->bindValue('languageId', $languageId, \Doctrine\DBAL\ParameterType::INTEGER);
            $result = $stmt->executeQuery();

            $row = $result->fetchAssociative();
            return $row ? $row['content'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

