<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Action;
use App\Entity\ActionTranslation;
use App\Entity\Language;
use App\Repository\ActionTranslationRepository;
use App\Repository\LanguageRepository;
use App\Service\Action\ActionTemplateContextBuilder;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use App\Exception\ServiceException;

/**
 * Manages action translations and resolves translation keys for admin/runtime display.
 *
 * In the scheduled-jobs flow this service is used to replace stored translation
 * keys with CMS default-language content without mutating the persisted job config.
 */
class AdminActionTranslationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly ActionTranslationRepository $actionTranslationRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly CacheService $cache,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly ActionTemplateContextBuilder $templateContextBuilder,
    ) {
    }

    /**
     * Get all translations for an action.
     *
     * @param int $actionId
     *   The action id whose translations should be loaded.
     * @param int|null $languageId
     *   Optional language id filter; when omitted, all languages are returned.
     *
     * @return array<int, array<string, mixed>>
     *   Formatted translation rows for the requested action.
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
     * Bulk create or update translations for an action.
     *
     * @param int $actionId
     *   The action id whose translations are being changed.
     * @param array<int, array<string, mixed>> $translations
     *   Translation payloads keyed by language id and translation key.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *   Created and updated translation entries grouped by operation.
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
                $languageId = $this->asInt($translationData['id_languages'] ?? null);
                $translationKey = $this->asString($translationData['translation_key'] ?? null);
                $content = $this->asString($translationData['content'] ?? null);

                // Reject deprecated `@user`-style placeholders: action template
                // content must use the canonical {{recipient.*}} / {{record.*}} /
                // {{system.*}} scopes rendered by ActionTemplateContextBuilder.
                if ($this->templateContextBuilder->hasLegacyPlaceholders($content)) {
                    throw new ServiceException(
                        sprintf(
                            'Translation "%s" uses deprecated @-placeholders. Use the {{recipient.*}}, {{record.*}} and {{system.*}} scopes instead.',
                            $translationKey
                        ),
                        Response::HTTP_BAD_REQUEST
                    );
                }

                // Verify language exists
                $language = $this->languageRepository->find($languageId);
                if (!$language instanceof Language) {
                    throw new ServiceException('Language not found: ' . $languageId, Response::HTTP_BAD_REQUEST);
                }

                // Check if translation already exists
                $existingTranslation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
                    $actionId,
                    $translationKey,
                    $languageId
                );

                if ($existingTranslation) {
                    // Update existing
                    $originalTranslation = clone $existingTranslation;
                    $existingTranslation->setContent($content);
                    $existingTranslation->setUpdatedAt(new \DateTimeImmutable());
                    $updatedTranslations[] = $this->formatTranslation($existingTranslation);
                } else {
                    // Create new
                    $translation = new ActionTranslation();
                    $translation->setAction($action);
                    $translation->setLanguage($language);
                    $translation->setTranslationKey($translationKey);
                    $translation->setContent($content);

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
     * Resolve a translation key for a preferred language with CMS default-language fallback.
     *
     * @param int $actionId
     *   The action that owns the translation key.
     * @param string $translationKey
     *   The translation key to resolve.
     * @param int $userLanguageId
     *   The preferred language id to try first.
     *
     * @return string
     *   The translated content, or the original key when no translation exists.
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
            $content = $translation->getContent();
            if ($content !== null) {
                return $content;
            }
        }

        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        if ($defaultLanguageId !== $userLanguageId) {
            $translation = $this->actionTranslationRepository->findByActionKeyAndLanguage(
                $actionId,
                $translationKey,
                $defaultLanguageId
            );

            if ($translation) {
                $content = $translation->getContent();
                if ($content !== null) {
                    return $content;
                }
            }
        }

        // Return the key itself as fallback
        return $translationKey;
    }

    /**
     * Resolve a translation key using the CMS default language.
     *
     * @param int $actionId
     *   The action that owns the translation key.
     * @param string $translationKey
     *   The translation key to resolve.
     *
     * @return string
     *   The CMS default-language translation, or the original key when missing.
     */
    public function resolveTranslationForDefaultLanguage(int $actionId, string $translationKey): string
    {
        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        return $this->resolveTranslation($actionId, $translationKey, $defaultLanguageId);
    }

    /**
     * Format a translation entity for the admin API.
     *
     * @param ActionTranslation $translation
     *   The translation entity to format.
     *
     * @return array<string, mixed>
     *   A normalized translation payload with CMS-timezone datetimes.
     */
    private function formatTranslation(ActionTranslation $translation): array
    {
        // Convert timezone for datetime fields
        $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());

        $createdAt = $translation->getCreatedAt();
        if ($createdAt) {
            $createdAt = $createdAt->setTimezone($cmsTimezone);
        }

        $updatedAt = $translation->getUpdatedAt();
        if ($updatedAt) {
            $updatedAt = $updatedAt->setTimezone($cmsTimezone);
        }

        $language = $translation->getLanguage();

        return [
            'id' => $translation->getId(),
            'translation_key' => $translation->getTranslationKey(),
            'content' => $translation->getContent(),
            'created_at' => $createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $updatedAt?->format('Y-m-d H:i:s'),
            'language' => [
                'id' => $language?->getId(),
                'locale' => $language?->getLocale(),
                'language' => $language?->getLanguage(),
            ],
        ];
    }

    /**
     * Invalidate cached translation lists after a write operation.
     *
     * @param int $actionId
     *   The action id whose translation views should be refreshed.
     */
    private function invalidateTranslationCache(int $actionId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_ACTIONS)
            ->invalidateAllListsInCategory();
    }
}
