<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Util\EntityUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class LanguageService extends BaseService
{

    public function __construct(
        private readonly LanguageRepository $languageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly CmsPreferenceService $cmsPreferenceService
    ) {
    }

    /**
     * All languages including the language-independent row.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllLanguages(): array
    {
        $cacheKey = 'all_languages';

        return $this->cache
            ->withCategory(CacheService::CATEGORY_LANGUAGES)
            ->getList($cacheKey, function () {
                $languages = $this->languageRepository->findAllLanguages();
                return array_values(array_map(function (Language $language) {
                    return EntityUtil::convertEntityToArray($language);
                }, $languages));
            });
    }

    /**
     * Real languages only (excludes the language-independent row).
     * Always returns the CMS default language first when configured, followed by other languages.
     *
     * @return list<array<string, mixed>>
     */
    public function getRealLanguages(): array
    {
        $cacheKey = 'real_languages';

        return $this->cache
            ->withCategory(CacheService::CATEGORY_LANGUAGES)
            ->getList(
                $cacheKey,
                function () {
                    // Clear any cached entities to avoid proxy objects
                    $this->entityManager->clear();

                    $languages = $this->languageRepository->findRealLanguages();

                    // Get default language from CMS preferences
                    $defaultLanguage = null;
                    $defaultLanguageId = null;

                    try {
                        $defaultLanguageIdFromPrefs = $this->cmsPreferenceService->getDefaultLanguageId();
                        if ($defaultLanguageIdFromPrefs) {
                            $defaultLanguage = $this->languageRepository->find($defaultLanguageIdFromPrefs);
                            $defaultLanguageId = $defaultLanguageIdFromPrefs;
                        }
                    } catch (\Exception $e) {
                        // If there's an error getting the default language, continue without it
                    }

                    // Convert entities to arrays
                    $languageArrays = array_values(array_map(function (Language $language) {
                        return EntityUtil::convertEntityToArray($language);
                    }, $languages));

                    // If we have a default language, ensure it's first in the array
                    if ($defaultLanguageId !== null) {
                        $defaultLanguageArray = null;
                        $otherLanguages = [];

                        foreach ($languageArrays as $langArray) {
                            if ($langArray['id'] === $defaultLanguageId) {
                                $defaultLanguageArray = $langArray;
                            } else {
                                $otherLanguages[] = $langArray;
                            }
                        }

                        // If default language was found in the results, put it first
                        if ($defaultLanguageArray !== null) {
                            return array_merge([$defaultLanguageArray], $otherLanguages);
                        }
                    }

                    return $languageArrays;
                }
            );
    }

    /**
     * Get a language by ID
     * 
     * @param int $id
     * @return array<string, mixed>
     * @throws NotFoundHttpException
     */
    public function getLanguageById(int $id): array
    {
        $cacheKey = "language_{$id}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_LANGUAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $id)
            ->getItem(
                $cacheKey,
                function () use ($id) {
                    $language = $this->languageRepository->find($id);
                    if (!$language) {
                        throw new NotFoundHttpException('Language not found');
                    }

                    return EntityUtil::convertEntityToArray($language);
                }
            );
    }

    /**
     * Create a new language
     * 
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createLanguage(array $data): array
    {
        $this->entityManager->beginTransaction();

        try {
            $this->validateLanguageData($data);

            $language = new Language();
            $language->setLocale($this->asString($data['locale']));
            $language->setLanguage($this->asString($data['language']));

            if (isset($data['csv_separator'])) {
                $language->setCsvSeparator($this->asString($data['csv_separator']));
            }

            $this->entityManager->persist($language);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'language',
                $language->getId(),
                $language,
                'Language created: ' . $language->getLanguage() . ' (' . $language->getLocale() . ')'
            );

            $this->entityManager->commit();

            // Invalidate language cache using new method
            $this->cache
                ->withCategory(CacheService::CATEGORY_LANGUAGES)
                ->invalidateAllListsInCategory();

            return EntityUtil::convertEntityToArray($language);
        } catch (Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Update an existing language
     * 
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws NotFoundHttpException
     */
    public function updateLanguage(int $id, array $data): array
    {
        $this->entityManager->beginTransaction();

        try {
            $language = $this->languageRepository->find($id);
            if (!$language) {
                throw new NotFoundHttpException('Language not found');
            }

            if ($id === Language::INDEPENDENT_ID) {
                throw new BadRequestHttpException('Cannot update the language-independent row');
            }

            // Store original values for logging
            $originalLanguage = clone $language;

            $this->validateLanguageData($data);

            if (isset($data['locale'])) {
                $language->setLocale($this->asString($data['locale']));
            }

            if (isset($data['language'])) {
                $language->setLanguage($this->asString($data['language']));
            }

            if (isset($data['csv_separator'])) {
                $language->setCsvSeparator($this->asString($data['csv_separator']));
            }

            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'language',
                $language->getId(),
                $language, // Pass the language object directly
                'Language updated: ' . $language->getLanguage() . ' (' . $language->getLocale() . ') [Original: ' . $originalLanguage->getLanguage() . ' (' . $originalLanguage->getLocale() . ')]'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific language
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $id);
            $this->cache
                ->withCategory(CacheService::CATEGORY_LANGUAGES)
                ->invalidateAllListsInCategory();

            return EntityUtil::convertEntityToArray($language);
        } catch (Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Delete a language
     * 
     * @param int $id
     * @return Language
     * @throws NotFoundHttpException
     * @throws BadRequestHttpException
     */
    public function deleteLanguage(int $id): Language
    {
        $this->entityManager->beginTransaction();

        try {
            $language = $this->languageRepository->find($id);
            if (!$language) {
                throw new NotFoundHttpException('Language not found');
            }

            if ($id === Language::INDEPENDENT_ID) {
                throw new BadRequestHttpException('Cannot delete the language-independent row');
            }

            // Store language data for logging before deletion
            $deletedLanguage = clone $language;
            $languageLocale = $language->getLocale();
            $languageName = $language->getLanguage();
            $languageId = $language->getId();

            $this->entityManager->remove($language);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'language',
                $languageId,
                $deletedLanguage,
                'Language deleted: ' . $languageName . ' (' . $languageLocale . ')'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific language
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $id);
            $this->cache
                ->withCategory(CacheService::CATEGORY_LANGUAGES)
                ->invalidateAllListsInCategory();

            return $deletedLanguage;
        } catch (Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Validate language data
     * 
     * @param array<string, mixed> $data
     * @throws BadRequestHttpException
     */
    private function validateLanguageData(array $data): void
    {
        if (!isset($data['locale']) || empty($data['locale'])) {
            throw new BadRequestHttpException('Locale is required');
        }

        if (!isset($data['language']) || empty($data['language'])) {
            throw new BadRequestHttpException('Language name is required');
        }

        if (isset($data['csv_separator']) && strlen($this->asString($data['csv_separator'])) !== 1) {
            throw new BadRequestHttpException('CSV separator must be a single character');
        }
    }
}
