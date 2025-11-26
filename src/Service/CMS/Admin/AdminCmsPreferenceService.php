<?php

namespace App\Service\CMS\Admin;

use App\Repository\LanguageRepository;
use App\Repository\PageRepository;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class AdminCmsPreferenceService extends BaseService
{
    private const SH_CMS_PREFERENCES_KEYWORD = 'sh-cms-preferences';

    public function __construct(
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly PageRepository $pageRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Get CMS preferences with entity scope caching
     *
     * @return array
     */
    public function getCmsPreferences(): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
            ->getItem(
                'cms_preferences',
                function () {
                    $preferences = $this->cmsPreferenceService->getCmsPreferences();

                    if (!$preferences || !$preferences['id']) {
                        throw new ServiceException('CMS preferences not found', Response::HTTP_NOT_FOUND);
                    }

                    $defaultLanguage = null;
                    if ($preferences['default_language_id']) {
                        $language = $this->languageRepository->find($preferences['default_language_id']);
                        if ($language) {
                            $defaultLanguage = [
                                'id' => $language->getId(),
                                'locale' => $language->getLocale(),
                                'language' => $language->getLanguage()
                            ];
                        }
                    }

                    // Add entity scope for the CMS preferences page
                    $this->cache
                        ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
                        ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $preferences['id'])
                        ->setItem('cms_preferences_scoped', [
                            'id' => $preferences['id'],
                            'callback_api_key' => $preferences['callback_api_key'],
                            'default_language_id' => $preferences['default_language_id'],
                            'default_language' => $defaultLanguage,
                            'anonymous_users' => $preferences['anonymous_users'],
                            'firebase_config' => $preferences['firebase_config'],
                            'default_timezone' => $preferences['default_timezone']
                        ]);

                    return [
                        'id' => $preferences['id'],
                        'callback_api_key' => $preferences['callback_api_key'],
                        'default_language_id' => $preferences['default_language_id'],
                        'default_language' => $defaultLanguage,
                        'anonymous_users' => $preferences['anonymous_users'],
                        'firebase_config' => $preferences['firebase_config'],
                        'default_timezone' => $preferences['default_timezone']
                    ];
                }
            );
    }

    /**
     * Update CMS preferences
     *
     * @param array $data
     * @return array
     */
    public function updateCmsPreferences(array $data): array
    {
        $this->entityManager->beginTransaction();

        try {
            $preferencesPage = $this->pageRepository->findOneBy(['keyword' => self::SH_CMS_PREFERENCES_KEYWORD]);

            if (!$preferencesPage) {
                throw new ServiceException('CMS preferences page not found', Response::HTTP_NOT_FOUND);
            }

            // Update callback API key if provided
            if (array_key_exists('callback_api_key', $data)) {
                $this->updatePageField($preferencesPage->getId(), 'callback_api_key', $data['callback_api_key']);
            }

            // Update default language if provided
            if (array_key_exists('default_language_id', $data)) {
                if ($data['default_language_id'] === null) {
                    $this->updatePageField($preferencesPage->getId(), 'default_language_id', null);
                } else {
                    $language = $this->languageRepository->find($data['default_language_id']);
                    if (!$language) {
                        throw new ServiceException('Language not found', Response::HTTP_BAD_REQUEST);
                    }
                    $this->updatePageField($preferencesPage->getId(), 'default_language_id', (string) $data['default_language_id']);
                }
            }

            // Update anonymous users if provided
            if (array_key_exists('anonymous_users', $data)) {
                $this->updatePageField($preferencesPage->getId(), 'anonymous_users', (string) (int) $data['anonymous_users']);
            }

            // Update firebase config if provided
            if (array_key_exists('firebase_config', $data)) {
                $this->updatePageField($preferencesPage->getId(), 'firebase_config', $data['firebase_config']);
            }

            // Update default timezone if provided
            if (array_key_exists('default_timezone', $data)) {
                $this->updatePageField($preferencesPage->getId(), 'default_timezone', $data['default_timezone'] ?: 'Europe/Zurich');
            }

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'pages',
                $preferencesPage->getId(),
                $preferencesPage,
                'CMS preferences updated'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for CMS preferences page
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, $preferencesPage->getId());
            $this->cache
                ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
                ->invalidateAllListsInCategory();

            return $this->getCmsPreferences();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Update a page field value
     *
     * @param int $pageId
     * @param string $fieldName
     * @param string|null $value
     */
    private function updatePageField(int $pageId, string $fieldName, ?string $value): void
    {
        $conn = $this->entityManager->getConnection();

        // Get field ID
        $fieldId = $conn->executeQuery(
            "SELECT id FROM fields WHERE name = :fieldName LIMIT 1",
            ['fieldName' => $fieldName]
        )->fetchOne();

        if (!$fieldId) {
            throw new ServiceException("Field '{$fieldName}' not found", Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Check if translation exists
        $existing = $conn->executeQuery(
            "SELECT id FROM pages_fields_translation WHERE id_pages = :pageId AND id_fields = :fieldId LIMIT 1",
            ['pageId' => $pageId, 'fieldId' => $fieldId]
        )->fetchOne();

        if ($existing) {
            // Update existing translation
            $conn->executeStatement(
                "UPDATE pages_fields_translation SET content = :content WHERE id_pages = :pageId AND id_fields = :fieldId",
                [
                    'content' => $value,
                    'pageId' => $pageId,
                    'fieldId' => $fieldId
                ]
            );
        } else {
            // Insert new translation (use default language ID 1)
            $conn->executeStatement(
                "INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content) VALUES (:pageId, :fieldId, '0000000001', :content)",
                [
                    'pageId' => $pageId,
                    'fieldId' => $fieldId,
                    'content' => $value
                ]
            );
        }
    }
}