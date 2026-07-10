<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Entity\PageTypeField;
use App\Entity\PagesFieldsTranslation;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\Traits\TranslationManagerTrait;
use App\Service\CMS\Admin\Traits\FieldValidatorTrait;
use App\Service\CMS\NavigationAssignmentService;
use App\Service\CMS\NavigationCacheInvalidator;
use App\Service\Core\BaseService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\UserContextAwareService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for handling page field operations
 */
class PageFieldService extends BaseService
{
    use TranslationManagerTrait;
    use FieldValidatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheService $cache,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly PagePublishedVersionRepairService $pagePublishedVersionRepairService,
        private readonly PageRouteService $pageRouteService,
        private readonly NavigationCacheInvalidator $navigationCacheInvalidator,
        private readonly NavigationAssignmentService $navigationAssignmentService,
    ) {
    }

    /**
     * Get page with its fields and translations
     * 
     * @param int $pageId The page ID
     * @return array<string, mixed> The page with its fields and translations
     * @throws ServiceException If page not found or access denied
     */
    public function getPageWithFields(int $pageId): array
    {
        $page = $this->pagePublishedVersionRepairService->loadPageRepairingDanglingPublishedVersion($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        // Try to get from cache first
        $cacheKey = "page_with_fields_{$page->getId()}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId())
            ->getItem(
                $cacheKey,
                function () use ($pageId) {
                    return $this->fetchPageWithFieldsFromDatabase($pageId);
                }
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPageWithFieldsFromDatabase(int $pageId): array
    {
        $page = $this->pagePublishedVersionRepairService->loadPageRepairingDanglingPublishedVersion($pageId);

        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        // Check if user has access to the page
        $this->userContextAwareService->checkAdminAccess((string) $page->getKeyword(), 'select');

        $pageType = $page->getPageType();
        if (!$pageType) {
            throw new ServiceException(
                sprintf("Page %s does not have a page type assigned", $page->getKeyword()),
                \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST
            );
        }

        // Get page type fields based on the page's type
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ptf', 'f', 'ft')
            ->from(PageTypeField::class, 'ptf')
            ->innerJoin('ptf.field', 'f')
            ->innerJoin('f.type', 'ft')
            ->where('ptf.pageType = :pageTypeId')
            ->setParameter('pageTypeId', $pageType->getId())
            ->orderBy('f.id', 'ASC');

        /** @var list<PageTypeField> $pageTypeFields */
        $pageTypeFields = $qb->getQuery()->getResult();

        // Get page fields associated with this page
        $pageFieldsMap = [];
        $pageFields = $this->entityManager->getRepository(PageTypeField::class)->findBy(['pageType' => $pageType->getId()]);
        foreach ($pageFields as $pageField) {
            $mapFieldId = $pageField->getField()?->getId();
            if ($mapFieldId !== null) {
                $pageFieldsMap[$mapFieldId] = $pageField;
            }
        }

        // Get all translations for this page's fields
        $translationsMap = [];
        $translations = $this->entityManager->getRepository(PagesFieldsTranslation::class)
            ->findBy(['page' => $page]);

        foreach ($translations as $translation) {
            $fieldId = $translation->getField()?->getId();
            $langId = $translation->getLanguage()?->getId();
            if ($fieldId === null || $langId === null) {
                continue;
            }
            if (!isset($translationsMap[$fieldId])) {
                $translationsMap[$fieldId] = [];
            }
            $translationsMap[$fieldId][$langId] = $translation;
        }

        // Format fields with translations
        $formattedFields = [];
        foreach ($pageTypeFields as $pageTypeField) {
            $field = $pageTypeField->getField();
            if (!$field) {
                continue;
            }
            $fieldId = (int) $field->getId();

            // Get the pageField if it exists for this field
            $pageField = $pageFieldsMap[$fieldId] ?? null;

            $fieldData = [
                'id' => $fieldId,
                'name' => $field->getName(),
                'title' => $pageField ? $pageField->getTitle() : null,
                'type' => $field->getType() ? $field->getType()->getName() : null,
                'default_value' => $pageField ? $pageField->getDefaultValue() : null,
                'help' => $pageField ? $pageField->getHelp() : null,
                'config' => $field->getConfig(),
                'display' => $field->isDisplay(),  // Whether it's a content field (1) or property field (0)
                'translations' => []
            ];

            // Handle translations based on display flag
            if ($field->isDisplay()) {
                // Content field (display=1) - can have translations for each language
                if (isset($translationsMap[$fieldId])) {
                    foreach ($translationsMap[$fieldId] as $translation) {
                        $language = $translation->getLanguage();
                        if (!$language) {
                            continue;
                        }
                        $fieldData['translations'][] = [
                            'language_id' => $language->getId(),
                            'language_code' => $language->getLocale(),
                            'content' => $translation->getContent()
                        ];
                    }
                }
            } else {
                // Property field (display=0) - use language_id = 1 only
                $propertyTranslation = $translationsMap[$fieldId][1] ?? null;
                if ($propertyTranslation) {
                    $fieldData['translations'][] = [
                        'language_id' => 1,
                        'language_code' => 'property',  // This is a property, not actually language-specific
                        'content' => $propertyTranslation->getContent()
                    ];
                }
            }

            $formattedFields[] = $fieldData;
        }

        $pageAccessType = $page->getPageAccessType();
        $pageSurface = $page->getPageSurface();

        // Return page data with fields and their translations
        return [
            'page' => [
                "id" => $page->getId(),
                "keyword" => $page->getKeyword(),
                "url" => $page->getUrl(),
                "parentPage" => null,
                "pageType" => [
                    "id" => $pageType->getId(),
                    "name" => $pageType->getName()
                ],
                "idType" => $page->getIdType(),
                "pageAccessType" => $pageAccessType ? [
                    "id" => $pageAccessType->getId(),
                    "typeCode" => "pageAccessTypes",
                    "lookupCode" => $pageAccessType->getLookupCode(),
                    "lookupValue" => $pageAccessType->getLookupValue(),
                    "lookupDescription" => $pageAccessType->getLookupDescription()
                ] : null,
                // CMS-in-CMS surface axis (issue #30). NULL FK resolves to
                // `public`; `pageSurface` carries the full lookup for editors.
                "pageSurface" => $pageSurface ? [
                    "id" => $pageSurface->getId(),
                    "typeCode" => "pageSurface",
                    "lookupCode" => $pageSurface->getLookupCode(),
                    "lookupValue" => $pageSurface->getLookupValue(),
                    "lookupDescription" => $pageSurface->getLookupDescription()
                ] : null,
                "surface" => $page->getPageSurfaceCode(),
                "headless" => $page->isHeadless(),
                "openAccess" => $page->isOpenAccess(),
                "system" => $page->isSystem(),
                "navigationMembership" => $this->navigationAssignmentService->getMembershipBadgesForPage((int) $page->getId()),
            ],
            'fields' => $formattedFields,
            // CMS-editable public route contract for the locked Routes panel
            // (issue #30). Round-trips with updatePage's `pageData.routes` sync.
            'routes' => $this->pageRouteService->getRoutesForPage((int) $page->getId())
        ];
    }

    /**
     * Update page field translations
     * 
     * @param Page $page The page entity
     * @param list<array<string, mixed>> $fields The fields to update
     * @throws ServiceException If validation fails
     */
    public function updatePageFields(Page $page, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        // Validate that all fields belong to the page's page type
        $fieldIds = array_map(fn ($v): int => $this->asInt($v), array_column($fields, 'fieldId'));
        $pageType = $page->getPageType();
        if (!$pageType) {
            throw new ServiceException(
                sprintf("Page %s does not have a page type assigned", $page->getKeyword()),
                \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST
            );
        }

        $this->validatePageTypeFields($fieldIds, (int) $pageType->getId(), $this->entityManager);

        // Update field translations using trait method
        $this->updatePageFieldTranslations((int) $page->getId(), $fields, $this->entityManager);

        // Invalidate page cache after updates
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();
        $this->navigationCacheInvalidator->invalidateForPage((int) $page->getId());
    }
}
