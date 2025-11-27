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
                            'default_language_id' => $preferences['default_language_id'],
                            'default_language' => $defaultLanguage,
                            'anonymous_users' => $preferences['anonymous_users'],
                            'firebase_config' => $preferences['firebase_config'],
                            'default_timezone' => $preferences['default_timezone']
                        ]);

                    return [
                        'id' => $preferences['id'],
                        'default_language_id' => $preferences['default_language_id'],
                        'default_language' => $defaultLanguage,
                        'anonymous_users' => $preferences['anonymous_users'],
                        'firebase_config' => $preferences['firebase_config'],
                        'default_timezone' => $preferences['default_timezone']
                    ];
                }
            );
    }

}