<?php

namespace App\Service\CMS\Admin;

use App\Entity\Action;
use App\Entity\ActionTranslation;
use App\Entity\Language;
use App\Repository\ActionRepository;
use App\Repository\ActionTranslationRepository;
use App\Repository\LanguageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use App\Exception\ServiceException;

class AdminActionTranslationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly ActionRepository $actionRepository,
        private readonly ActionTranslationRepository $actionTranslationRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * Get all translations for an action
     */
    public function getTranslations(int $actionId, ?int $languageId = null): array
    {
        // Verify action exists
        $action = $this->entityManager->find(Action::class, $actionId);
        if (!$action instanceof Action) {
            throw new ServiceException('Action not found', Response::HTTP_NOT_FOUND);
        }

        $cacheKey = "action_translations_{$actionId}" . ($languageId ? "_lang_{$languageId}" : '');

        return $this->cache
            ->withCategory(CacheService::CATEGORY_ACTIONS)
            ->getItem(
                $cacheKey,
                function () use ($actionId, $languageId) {
                    $translations = $this->actionTranslationRepository->findByActionId($actionId, $languageId);
                    return array_map([$this, 'formatTranslation'], $translations);
                }
            );
    }

    /**
     * Create or update a translation
     */
    public function createTranslation(int $actionId, array $data): array
    {
        $this->entityManager->beginTransaction();
        try {
            // Verify action exists
            $action = $this->entityManager->find(Action::class, $actionId);
            if (!$action instanceof Action) {
                throw new ServiceException('Action not found', Response::HTTP_NOT_FOUND);
            }

            // Verify language exists
            $language = $this->languageRepository->find($data['id_languages']);
            if (!$language instanceof Language) {
                throw new ServiceException('Language not found', Response::HTTP_NOT_FOUND);
            }

            // Check if translation already exists
            $existingTranslation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
                $actionId,
                $data['translation_key'],
                $data['id_languages']
            );

            if ($existingTranslation) {
                // Update existing translation
                $originalTranslation = clone $existingTranslation;
                $existingTranslation->setContent($data['content']);
                $existingTranslation->setUpdatedAt(new \DateTime());

                $this->entityManager->flush();

                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_UPDATE,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'action_translations',
                    $existingTranslation->getId(),
                    (object) ['old_translation' => $originalTranslation, 'new_translation' => $existingTranslation],
                    'Action translation updated: ' . $existingTranslation->getTranslationKey()
                );

                $this->entityManager->commit();
                $this->invalidateTranslationCache($actionId);

                return $this->formatTranslation($existingTranslation);
            }

            // Create new translation
            $translation = new ActionTranslation();
            $translation->setAction($action);
            $translation->setLanguage($language);
            $translation->setTranslationKey($data['translation_key']);
            $translation->setContent($data['content']);

            $this->entityManager->persist($translation);
            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'action_translations',
                $translation->getId(),
                $translation,
                'Action translation created: ' . $translation->getTranslationKey()
            );

            $this->entityManager->commit();
            $this->invalidateTranslationCache($actionId);

            return $this->formatTranslation($translation);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to create translation: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Update an existing translation
     */
    public function updateTranslation(int $actionId, int $translationId, array $data): array
    {
        $this->entityManager->beginTransaction();
        try {
            // Verify translation exists and belongs to the action
            $translation = $this->actionTranslationRepository->findOneByActionAndId($actionId, $translationId);
            if (!$translation instanceof ActionTranslation) {
                throw new ServiceException('Translation not found', Response::HTTP_NOT_FOUND);
            }

            $originalTranslation = clone $translation;
            $translation->setContent($data['content']);
            $translation->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'action_translations',
                $translation->getId(),
                (object) ['old_translation' => $originalTranslation, 'new_translation' => $translation],
                'Action translation updated: ' . $translation->getTranslationKey()
            );

            $this->entityManager->commit();
            $this->invalidateTranslationCache($actionId);

            return $this->formatTranslation($translation);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to update translation: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Delete a translation
     */
    public function deleteTranslation(int $actionId, int $translationId): bool
    {
        $this->entityManager->beginTransaction();
        try {
            // Verify translation exists and belongs to the action
            $translation = $this->actionTranslationRepository->findOneByActionAndId($actionId, $translationId);
            if (!$translation instanceof ActionTranslation) {
                throw new ServiceException('Translation not found', Response::HTTP_NOT_FOUND);
            }

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'action_translations',
                $translation->getId(),
                $translation,
                'Action translation deleted: ' . $translation->getTranslationKey()
            );

            $this->entityManager->remove($translation);
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->invalidateTranslationCache($actionId);

            return true;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to delete translation: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Bulk create/update translations
     */
    public function bulkCreateTranslations(int $actionId, array $translations): array
    {
        $this->entityManager->beginTransaction();
        try {
            // Verify action exists
            $action = $this->entityManager->find(Action::class, $actionId);
            if (!$action instanceof Action) {
                throw new ServiceException('Action not found', Response::HTTP_NOT_FOUND);
            }

            $createdTranslations = [];
            $updatedTranslations = [];

            foreach ($translations as $translationData) {
                // Verify language exists
                $language = $this->languageRepository->find($translationData['id_languages']);
                if (!$language instanceof Language) {
                    throw new ServiceException('Language not found: ' . $translationData['id_languages'], Response::HTTP_BAD_REQUEST);
                }

                // Check if translation already exists
                $existingTranslation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
                    $actionId,
                    $translationData['translation_key'],
                    $translationData['id_languages']
                );

                if ($existingTranslation) {
                    // Update existing
                    $originalTranslation = clone $existingTranslation;
                    $existingTranslation->setContent($translationData['content']);
                    $existingTranslation->setUpdatedAt(new \DateTime());
                    $updatedTranslations[] = $this->formatTranslation($existingTranslation);
                } else {
                    // Create new
                    $translation = new ActionTranslation();
                    $translation->setAction($action);
                    $translation->setLanguage($language);
                    $translation->setTranslationKey($translationData['translation_key']);
                    $translation->setContent($translationData['content']);

                    $this->entityManager->persist($translation);
                    $createdTranslations[] = $this->formatTranslation($translation);
                }
            }

            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'action_translations',
                $actionId,
                (object) [
                    'created_count' => count($createdTranslations),
                    'updated_count' => count($updatedTranslations)
                ],
                'Bulk action translations operation for action ID: ' . $actionId
            );

            $this->entityManager->commit();
            $this->invalidateTranslationCache($actionId);

            return [
                'created' => $createdTranslations,
                'updated' => $updatedTranslations
            ];
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Failed to bulk create translations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Get missing translations for an action and language
     */
    public function getMissingTranslations(int $actionId, int $languageId): array
    {
        // Verify action exists
        $action = $this->entityManager->find(Action::class, $actionId);
        if (!$action instanceof Action) {
            throw new ServiceException('Action not found', Response::HTTP_NOT_FOUND);
        }

        // Verify language exists
        $language = $this->languageRepository->find($languageId);
        if (!$language instanceof Language) {
            throw new ServiceException('Language not found', Response::HTTP_NOT_FOUND);
        }

        // Extract translation keys from action config
        $config = $action->getConfig();
        if (!$config) {
            return [];
        }

        $configData = json_decode($config, true);
        if (!$configData || !isset($configData['blocks'])) {
            return [];
        }

        $translationKeys = $this->extractTranslationKeysFromConfig($configData);

        // Find existing translations
        $existingTranslations = $this->actionTranslationRepository->findKeysByActionAndLanguage($actionId, $languageId);
        $existingKeys = array_column($existingTranslations, 'translation_key');

        // Return missing keys
        return array_values(array_diff($translationKeys, $existingKeys));
    }

    /**
     * Resolve translation for execution
     */
    public function resolveTranslation(int $actionId, string $translationKey, int $userLanguageId): string
    {
        // Try to get translation for user's language
        $translation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
            $actionId,
            $translationKey,
            $userLanguageId
        );

        if ($translation) {
            return $translation->getContent();
        }

        // Try default language (assuming id=1 is the default)
        $translation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
            $actionId,
            $translationKey,
            1 // Default language ID
        );

        if ($translation) {
            return $translation->getContent();
        }

        // Return the key itself as fallback
        return $translationKey;
    }

    /**
     * Extract translation keys from action config
     */
    private function extractTranslationKeysFromConfig(array $config): array
    {
        $keys = [];

        if (isset($config['blocks']) && is_array($config['blocks'])) {
            foreach ($config['blocks'] as $blockIndex => $block) {
                if (isset($block['block_name']) && is_string($block['block_name'])) {
                    $keys[] = "block_{$blockIndex}.name";
                }

                if (isset($block['jobs']) && is_array($block['jobs'])) {
                    foreach ($block['jobs'] as $jobIndex => $job) {
                        if (isset($job['job_name']) && is_string($job['job_name'])) {
                            $keys[] = "block_{$blockIndex}.job_{$jobIndex}.name";
                        }

                        if (isset($job['notification']) && is_array($job['notification'])) {
                            $keys = array_merge($keys, $this->extractNotificationKeys($job['notification'], $blockIndex, $jobIndex));
                        }
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Extract notification translation keys
     */
    private function extractNotificationKeys(array $notification, int $blockIndex, int $jobIndex): array
    {
        $keys = [];
        $translatableFields = ['subject', 'body', 'from_name', 'from_email'];

        foreach ($translatableFields as $field) {
            if (isset($notification[$field]) && is_string($notification[$field])) {
                $keys[] = "block_{$blockIndex}.job_{$jobIndex}.notification.{$field}";
            }
        }

        return $keys;
    }

    /**
     * Format translation for API response
     */
    private function formatTranslation(ActionTranslation $translation): array
    {
        return [
            'id' => $translation->getId(),
            'translation_key' => $translation->getTranslationKey(),
            'content' => $translation->getContent(),
            'created_at' => $translation->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $translation->getUpdatedAt()->format('Y-m-d H:i:s'),
            'language' => [
                'id' => $translation->getLanguage()->getId(),
                'locale' => $translation->getLanguage()->getLocale(),
                'language' => $translation->getLanguage()->getLanguage(),
            ],
        ];
    }

    /**
     * Invalidate translation cache
     */
    private function invalidateTranslationCache(int $actionId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_ACTIONS)
            ->invalidateAllListsInCategory();
    }
}
