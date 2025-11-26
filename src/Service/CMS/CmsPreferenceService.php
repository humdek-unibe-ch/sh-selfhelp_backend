<?php

namespace App\Service\CMS;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralized service for managing CMS preferences from sh-cms-preferences page
 *
 * This service consolidates all CMS preference operations to eliminate duplication
 * between different services that need to access CMS preferences.
 */
class CmsPreferenceService extends BaseService
{
    private const SH_CMS_PREFERENCES_KEYWORD = 'sh-cms-preferences';
    private const PF_CALLBACK_API_KEY = 'callback_api_key';
    private const PF_DEFAULT_LANGUAGE_ID = 'default_language_id';
    private const PF_ANONYMOUS_USERS = 'anonymous_users';
    private const PF_FIREBASE_CONFIG = 'firebase_config';
    private const PF_DEFAULT_TIMEZONE = 'default_timezone';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Get CMS preferences from the page-based system
     *
     * @return array Array containing all CMS preference values
     */
    public function getCmsPreferences(): array
    {
        $cacheKey = "cms_preferences_all";
        $preferencesPage = $this->getPreferencesPage();

        if (!$preferencesPage) {
            return $this->getDefaultPreferences();
        }

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $preferencesPage->getId())
            ->getItem($cacheKey, function () use ($preferencesPage) {
                $fieldValues = $this->getAllPageFieldValues($preferencesPage);

                return [
                    'id' => $preferencesPage->getId(),
                    'callback_api_key' => $fieldValues[self::PF_CALLBACK_API_KEY] ?? null,
                    'default_language_id' => $fieldValues[self::PF_DEFAULT_LANGUAGE_ID] ?? null,
                    'anonymous_users' => (int) ($fieldValues[self::PF_ANONYMOUS_USERS] ?? 0),
                    'firebase_config' => $fieldValues[self::PF_FIREBASE_CONFIG] ?? null,
                    'default_timezone' => $fieldValues[self::PF_DEFAULT_TIMEZONE] ?? 'Europe/Zurich'
                ];
            });
    }

    /**
     * Get callback API key
     *
     * @return string|null
     */
    public function getCallbackApiKey(): ?string
    {
        $preferences = $this->getCmsPreferences();
        return $preferences['callback_api_key'];
    }

    /**
     * Get default language ID
     *
     * @return int|null
     */
    public function getDefaultLanguageId(): ?int
    {
        $preferences = $this->getCmsPreferences();
        $languageId = $preferences['default_language_id'];
        return $languageId ? (int) $languageId : null;
    }

    /**
     * Get anonymous users count
     *
     * @return int
     */
    public function getAnonymousUsers(): int
    {
        $preferences = $this->getCmsPreferences();
        return $preferences['anonymous_users'];
    }

    /**
     * Get Firebase config
     *
     * @return string|null
     */
    public function getFirebaseConfig(): ?string
    {
        $preferences = $this->getCmsPreferences();
        return $preferences['firebase_config'];
    }

    /**
     * Get default timezone
     *
     * @return string
     */
    public function getDefaultTimezone(): string
    {
        $preferences = $this->getCmsPreferences();
        return $preferences['default_timezone'] ?: 'Europe/Zurich';
    }

    /**
     * Get all field values for a page in a single query
     *
     * @param Page $page The page entity
     * @return array Associative array of field names to values
     */
    private function getAllPageFieldValues(Page $page): array
    {
        try {
            $conn = $this->entityManager->getConnection();
            $sql = "
                SELECT f.name, pft.content
                FROM pages_fields_translation pft
                INNER JOIN fields f ON pft.id_fields = f.id
                WHERE pft.id_pages = :pageId
                AND pft.id_languages = '0000000001'
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('pageId', $page->getId(), \PDO::PARAM_INT);
            $result = $stmt->executeQuery();

            $fieldValues = [];
            while ($row = $result->fetchAssociative()) {
                $fieldValues[$row['name']] = $row['content'];
            }

            return $fieldValues;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the CMS preferences page
     *
     * @return Page|null The CMS preferences page or null if not found
     */
    private function getPreferencesPage(): ?Page
    {
        try {
            return $this->pageRepository->findOneBy(['keyword' => self::SH_CMS_PREFERENCES_KEYWORD]);
        } catch (\Exception $e) {
            return null;
        }
    }


    /**
     * Get default preferences when page is not found
     *
     * @return array
     */
    private function getDefaultPreferences(): array
    {
        return [
            'id' => null,
            'callback_api_key' => null,
            'default_language_id' => null,
            'anonymous_users' => 0,
            'firebase_config' => null,
            'default_timezone' => 'Europe/Zurich'
        ];
    }
}
