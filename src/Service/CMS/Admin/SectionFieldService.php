<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\DataTable;
use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\StylesField;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\Traits\TranslationManagerTrait;
use App\Service\CMS\Admin\Traits\FieldValidatorTrait;
use App\Service\CMS\DataTableService;
use App\Service\Core\BaseService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminAssetService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for handling section field operations
 */
class SectionFieldService extends BaseService
{
    use TranslationManagerTrait;
    use FieldValidatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataTableService $dataTableService,
        private readonly CacheService $cache,
        private readonly AdminAssetService $adminAssetService
    ) {
    }

    /**
     * Get section fields with translations
     * 
     * @param Section $section The section entity
     * @return list<array<string, mixed>> The formatted fields with translations
     */
    public function getSectionFields(Section $section): array
    {
        // Try to get from cache first
        $cacheKey = "section_fields_{$section->getId()}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $section->getId())
            ->getItem(
                $cacheKey,
                function () use ($section) {
                    return $this->fetchSectionFieldsFromDatabase($section);
                }
            );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSectionFieldsFromDatabase(Section $section): array
    {

        // Get style and its fields
        $style = $section->getStyle();
        if (!$style) {
            return [];
        }

        // Get all StylesField for this style ordered by priority asc and field name asc
        $stylesFields = $style->getStylesFields()?->toArray() ?? [];
        usort($stylesFields, function (StylesField $a, StylesField $b): int {
            $priorityA = $a->getField()?->getType()?->getPosition() ?? PHP_INT_MAX;
            $priorityB = $b->getField()?->getType()?->getPosition() ?? PHP_INT_MAX;
            if ($priorityA !== $priorityB) {
                return $priorityA - $priorityB;
            }
            return strcasecmp($this->asString($a->getField()?->getName()), $this->asString($b->getField()?->getName()));
        });

        // Fetch all field translations for this section
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t, l, f, ft')
            ->from(SectionsFieldsTranslation::class, 't')
            ->leftJoin('t.language', 'l')
            ->leftJoin('t.field', 'f')
            ->leftJoin('f.type', 'ft')
            ->where('t.section = :section')
            ->setParameter('section', $section);
        /** @var list<SectionsFieldsTranslation> $translations */
        $translations = $qb->getQuery()->getResult();

        // Group translations by field and language
        /** @var array<int, array<int, array{content: mixed, meta: mixed}>> $translationsByFieldLang */
        $translationsByFieldLang = [];
        foreach ($translations as $tr) {
            $fieldId = (int) $tr->getField()?->getId();
            $langId = (int) $tr->getLanguage()?->getId();
            if (!isset($translationsByFieldLang[$fieldId])) {
                $translationsByFieldLang[$fieldId] = [];
            }
            $translationsByFieldLang[$fieldId][$langId] = [
                'content' => $tr->getContent(),
                'meta' => $tr->getMeta(),
            ];
        }

        // Format fields with translations
        $formattedFields = [];
        foreach ($stylesFields as $stylesField) {
            $field = $stylesField->getField();
            if (!$field)
                continue;

            $fieldId = (int) $field->getId();
            $fieldTypeName = $field->getType()?->getName();

            $fieldData = [
                'id' => $fieldId,
                'name' => $field->getName(),
                'title' => $stylesField->getTitle(),
                'type' => $fieldTypeName,
                'default_value' => $stylesField->getDefaultValue(),
                'help' => $stylesField->getHelp(),
                'disabled' => $stylesField->isDisabled(),
                'hidden' => $stylesField->getHidden(),
                'display' => $field->isDisplay(),
                'config' => $field->getConfig() ?? $this->getFieldConfig($this->asString($fieldTypeName)),
                'translations' => [],
            ];

            $translations = [];

            // Handle translations based on display flag
            if ($field->isDisplay()) {
                // Content field (display=1) - can have translations for each language
                if (isset($translationsByFieldLang[$fieldId])) {
                    foreach ($translationsByFieldLang[$fieldId] as $langId => $translation) {
                        $translations[] = [
                            'language_id' => $langId,
                            'content' => $translation['content'],
                            'meta' => $translation['meta']
                        ];
                    }
                }
            } else {
                // Property field (display=0) - use language_id = 1 only
                if (isset($translationsByFieldLang[$fieldId][1])) {
                    $propertyTranslation = $translationsByFieldLang[$fieldId][1];
                    $translations[] = [
                        'language_id' => 1,
                        'language_code' => 'all',  // This is a property, not actually language-specific
                        'content' => $propertyTranslation['content'],
                        'meta' => $propertyTranslation['meta']
                    ];
                }
            }

            $fieldData['translations'] = $translations;
            $formattedFields[] = $fieldData;
        }

        return $formattedFields;
    }

    /**
     * Get field configuration based on field type
     * 
     * @param string $fieldType The field type
     * @return array<string, mixed> The field configuration
     */
    private function getFieldConfig(string $fieldType): array
    {
        $options = [];
        if ($fieldType === 'select-group') {
            // format ["value" => "group_id", "text" => "group_name"]
            $options = $this->getGroups();
        }
        if ($fieldType === 'select-data_table') {
            // format ["value" => "data_table_id", "text" => "data_table_name"]
            $options = $this->getDataTables();
        }
        if ($fieldType === 'select-page-keyword') {
            // format ["value" => "page_id", "text" => "page_keyword"]
            $options = $this->getPageKeywords();
        }
        if ($fieldType === 'select-image') {
            // format ["value" => "file_path", "text" => "file_name"]
            $options = $this->getImages();
        }
        if ($fieldType === 'select-video') {
            // format ["value" => "file_path", "text" => "file_name"]
            $options = $this->getVideos();
        }
        $config = [];

        if (in_array($fieldType, ['select-group', 'select-data_table', 'select-css', 'select-page-keyword', 'select-image', 'select-video'])) {
            $config = [
                'multiSelect' => in_array($fieldType, ['select-group', 'select-css']),
                'creatable' => in_array($fieldType, ['select-css', 'select-page-keyword', 'select-image', 'select-video']),
                'separator' => in_array($fieldType, ['select-css']) ? ' ' : ',',
                'options' => $options
            ];

            // Add API URL for CSS classes to allow frontend to fetch on demand
            if ($fieldType === 'select-css') {
                $config['apiUrl'] = '/cms-api/v1/frontend/css-classes';
            }

            if ($fieldType === 'select-page-keyword') {
                $config['searchable'] = true;
                $config['clearable'] = true;
            }
        }

        return $config;
    }

    /**
     * Get groups for select-group field type
     * 
     * @return list<array<string, mixed>> The groups formatted as options
     */
    private function getGroups(): array
    {
        $cacheKey = "groups";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->getList(
                $cacheKey,
                function () {
                    $qb = $this->entityManager->createQueryBuilder();
                    $qb->select('g.id, g.name')
                        ->from(Group::class, 'g')
                        ->orderBy('g.name', 'ASC');

                    /** @var list<array{id: mixed, name: mixed}> $groups */
                    $groups = $qb->getQuery()->getResult();

                    return array_map(fn (array $group): array => [
                        'value' => $this->asString($group['id']),
                        'text' => $group['name']
                    ], $groups);
                }
            );
    }

    /**
     * Get data tables for select-data_table field type
     * 
     * @return list<array<string, mixed>> The data tables formatted as options
     */
    private function getDataTables(): array
    {
        $cacheKey = "data_tables";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->getList(
                $cacheKey,
                function () {
                    $qb = $this->entityManager->createQueryBuilder();
                    $qb->select('dt.id, dt.name')
                        ->from(DataTable::class, 'dt')
                        ->orderBy('dt.name', 'ASC');

                    /** @var list<array{id: mixed, name: mixed}> $dataTables */
                    $dataTables = $qb->getQuery()->getResult();

                    return array_map(fn (array $table): array => [
                        'value' => $this->asString($table['id']),
                        'text' => $table['name']
                    ], $dataTables);
                }
            );
    }

    /**
     * Get page keywords for select-page-keyword field type
     *
     * @return list<array<string, mixed>> The page keywords formatted as options
     */
    public function getPageKeywords(): array
    {
        $cacheKey = "page_keywords";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getList(
                $cacheKey,
                function () {
                    $qb = $this->entityManager->createQueryBuilder();
                    $qb->select('p.id, p.keyword')
                        ->from(Page::class, 'p')
                        ->where('p.keyword IS NOT NULL')
                        ->orderBy('p.keyword', 'ASC');

                    /** @var list<array{id: mixed, keyword: mixed}> $pages */
                    $pages = $qb->getQuery()->getResult();

                    return array_map(fn (array $page): array => [
                        'value' => $page['keyword'],
                        'text' => $page['keyword']
                    ], $pages);
                }
            );
    }

    /**
     * Get images for select-image field type
     *
     * @return list<array<string, mixed>> The images formatted as options with relative paths
     */
    private function getImages(): array
    {
        $cacheKey = "images_list";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_ASSETS)
            ->getList(
                $cacheKey,
                function () {
                    // Get all assets and filter for image types
                    $allAssets = $this->adminAssetService->getAllAssets(1, 1000); // Get first 1000 assets for initial load
                    /** @var list<array<string, mixed>> $assetList */
                    $assetList = is_array($allAssets['assets'] ?? null) ? $allAssets['assets'] : [];

                    $images = array_filter($assetList, function (array $asset): bool {
                        $extension = strtolower(pathinfo($this->asString($asset['file_name'] ?? ''), PATHINFO_EXTENSION));
                        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
                    });

                    return array_map(function (array $image): array {
                        return [
                            'value' => $image['file_path'] ?? null, // Use relative path as value
                            'text' => $image['file_name'] ?? null
                        ];
                    }, array_values($images));
                }
            );
    }

    /**
     * Get videos for select-video field type
     *
     * @return list<array<string, mixed>> The videos formatted as options with relative paths
     */
    private function getVideos(): array
    {
        $cacheKey = "videos_list";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_ASSETS)
            ->getList(
                $cacheKey,
                function () {
                    // Get all assets and filter for video types
                    $allAssets = $this->adminAssetService->getAllAssets(1, 1000); // Get first 1000 assets for initial load
                    /** @var list<array<string, mixed>> $assetList */
                    $assetList = is_array($allAssets['assets'] ?? null) ? $allAssets['assets'] : [];

                    $videos = array_filter($assetList, function (array $asset): bool {
                        $extension = strtolower(pathinfo($this->asString($asset['file_name'] ?? ''), PATHINFO_EXTENSION));
                        return in_array($extension, ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm']);
                    });

                    return array_map(function (array $video): array {
                        return [
                            'value' => $video['file_path'] ?? null, // Use relative path as value
                            'text' => $video['file_name'] ?? null
                        ];
                    }, array_values($videos));
                }
            );
    }

    /**
     * Update section field translations
     * 
     * @param Section $section The section entity
     * @param list<array<string, mixed>> $contentFields Content fields (display=1)
     * @param list<array<string, mixed>> $propertyFields Property fields (display=0)
     * @throws ServiceException If validation fails
     */
    public function updateSectionFields(Section $section, array $contentFields, array $propertyFields): void
    {
        // Validate that all fields belong to the section's style
        $allFieldIds = array_map(fn ($v): int => $this->asInt($v), array_merge(
            array_column($contentFields, 'fieldId'),
            array_column($propertyFields, 'fieldId')
        ));

        $style = $section->getStyle();
        if (!empty($allFieldIds) && $style !== null) {
            $this->validateStyleFields($allFieldIds, (int) $style->getId(), $this->entityManager);
        }

        // Check if displayName field is being updated for form sections
        $isFormSection = $this->dataTableService->isFormSection($section);
        $displayNameUpdate = null;
        if ($isFormSection) {
            $displayNameUpdate = $this->extractDisplayNameUpdate($propertyFields);
        }

        // Update field translations using trait method
        $this->updateSectionFieldTranslations((int) $section->getId(), $contentFields, $propertyFields, $this->entityManager);

        // Sync displayName to dataTable if this is a form section and displayName was updated
        if ($isFormSection && $displayNameUpdate !== null) {
            $this->dataTableService->updateDataTableDisplayName($section, $displayNameUpdate);
        }

        // Invalidate section cache after updates
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SECTION, (int) $section->getId());
        $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->invalidateAllListsInCategory();
    }

    /**
     * Extract displayName update from field updates
     * 
     * @param list<array<string, mixed>> $propertyFields Property fields
     * @return string|null The new displayName value if found
     */
    private function extractDisplayNameUpdate(array $propertyFields): ?string
    {
        // Check both content and property fields for displayName field
        foreach ($propertyFields as $fieldUpdate) {
            // Find the field entity to check its name
            $fieldId = $fieldUpdate['fieldId'] ?? null;
            $field = $this->entityManager->getRepository(Field::class)->find($fieldId);

            if ($field && $field->getName() === 'name') {
                // Get the first translation content (assuming language_id = 1 for displayName)
                $content = $fieldUpdate['value'] ?? null;
                if ($content) {
                    return $this->asString($content);
                }
            }
        }

        return null;
    }
}
