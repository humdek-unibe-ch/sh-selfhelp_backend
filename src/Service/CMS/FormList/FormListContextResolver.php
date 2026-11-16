<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataFilterJson;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataQuery\LegacyFilterTranslator;
use App\Service\CMS\DataService;
use App\Service\CMS\Effective\EffectiveFormFactory;
use App\Service\CMS\Effective\EffectivePage;
use App\Service\CMS\Effective\EffectivePageResolver;
use App\Routing\PageRouteResolverService;
use App\Service\CMS\Frontend\PageService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\CMS\FormValidationService;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\UserContextAwareService;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the list a request names, and refuses everything the page itself
 * would refuse. A list is only ever reached through a page: the page must be
 * readable by the visitor, the section must sit on it and be visible there,
 * and the section must be a list. Only then are the configuration and the
 * table read.
 *
 * The page the list is read from is the effective page: the published version
 * for a visitor, the draft for an explicit preview of a signed-in user. The
 * configuration, the inline form, the key of the list and the layout of a
 * record all come from that one version, never from the live tables.
 */
class FormListContextResolver extends BaseService
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageService $pageService,
        private readonly FormValidationService $formValidation,
        private readonly FormListConfigReader $configReader,
        private readonly FormListGuard $guard,
        private readonly UserContextAwareService $userContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly DataQueryEngine $engine,
        private readonly DataService $dataService,
        private readonly EffectivePageResolver $effectivePages,
        private readonly EffectiveFormFactory $formFactory,
        private readonly PageRouteResolverService $routes,
        private readonly DataTableFilterService $dataTableFilterService,
        private readonly LegacyFilterTranslator $legacyFilterTranslator,
        private readonly FormFieldKeyResolver $keyResolver,
    ) {
    }

    /**
     * Participants need the page's `select` right and nothing more: groups see, roles edit. What they may
     * do with the records is decided by the list's operations and the data table's grants, where they act.
     *
     * @param string|null $mode `web` or `mobile`, the platform the caller renders for
     * @param bool $preview act on the draft of the page (an explicit preview of a signed-in user)
     * @param array<string, string> $route the route parameters of the page the client shows (`/team/7` has `record_id` = 7);
     *                                     believed only when one of the page's routes accepts them (see PageRouteResolverService::resolveParams)
     * @throws \App\Exception\ServiceException 404 page, 401 a preview without a user, 403 section not on the page or hidden, 400 not a list or route parameters the page does not have, 500 broken configuration
     */
    public function resolve(int $pageId, int $sectionId, ?int $languageId = null, ?string $mode = null, bool $preview = false, array $route = []): FormListContext
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        try {
            $this->pageService->assertPageReadable($page, $mode, 'select');
        } catch (\App\Exception\ServiceException $e) {
            // Contract 1: guests on a closed page get 401; anyone else who may
            // not see the page gets 404 — never 403 that reveals the page.
            if ($e->getCode() === Response::HTTP_FORBIDDEN) {
                if ($this->userContext->getCurrentUser() === null) {
                    throw new \App\Exception\ServiceException('Authentication required', Response::HTTP_UNAUTHORIZED);
                }
                $this->throwNotFound('Page not found');
            }
            throw $e;
        }
        $effective = $this->effectivePages->forPage($page, $preview);
        $this->formValidation->assertSectionVisibleIn($effective, $sectionId);

        $matched = null;
        if ($route !== []) {
            $matched = $this->routes->resolveParams($pageId, $route);
            if ($matched === null) {
                throw new \App\Exception\ServiceException(
                    'The page has no route with these parameters.',
                    Response::HTTP_BAD_REQUEST,
                    ['reason' => 'route_mismatch', 'parameter' => 'route'],
                );
            }
        }

        return $this->contextIn($page, $effective, $sectionId, $languageId, true, $matched['route_params'] ?? [], $matched['route_requirements'] ?? []);
    }

    /**
     * The context of a list on an effective page whose access has already been checked.
     *
     * @param bool $withTemplate also build the row template of a data view with the layout `custom`
     * @param array<string, string> $routeParams the validated route parameters of the page (see {@see self::resolve()})
     * @param array<string, string> $routeRequirements what the matching route requires of them
     * @throws \App\Exception\ServiceException 400 not a list, 500 broken configuration
     */
    public function contextIn(
        Page $page,
        EffectivePage $effective,
        int $sectionId,
        ?int $languageId,
        bool $withTemplate,
        array $routeParams = [],
        array $routeRequirements = [],
    ): FormListContext {
        $style = $effective->styleOf($sectionId);
        if ($style === StyleNames::STYLE_ENTRY_RECORD) {
            return $this->entryRecordContext($page, $effective, $sectionId, $languageId, $routeParams, $routeRequirements);
        }
        if ($style === StyleNames::STYLE_ENTRY_LIST) {
            return $this->entryListContext($page, $effective, $sectionId, $languageId, $routeParams, $routeRequirements);
        }
        if ($style === null || !in_array($style, StyleNames::LIST_STYLE_NAMES, true)) {
            throw new \App\Exception\ServiceException('The section is not a list.', Response::HTTP_BAD_REQUEST, ['reason' => 'not_a_list']);
        }

        $languageId = $this->pageService->determineLanguageId($languageId);
        $locale = $this->localeOf($languageId);
        $config = $this->configReader->readIn($effective, $sectionId, $languageId, $locale);

        $rows = $effective->rows();
        $this->assertKeyIsUnique($config, $effective, $rows);

        $user = $this->userContext->getCurrentUser();
        $tableId = $this->guard->tableIdOf($config, $rows);
        $formSectionId = $config->isEditable() ? FormListLayout::inlineFormSectionId($rows, $sectionId) : null;
        $form = $formSectionId === null
            ? null
            : $this->formFactory->build($effective, $formSectionId, fn (array $row): bool => $this->formValidation->conditionHolds($row));
        // F169: every input the form declares, including ones never saved (no
        // data_cols row yet). data-view / public lists have no inline form; the
        // owning form is still reachable via the table name (section id).
        $optionalColumns = $tableId !== null ? $this->optionalColumnsForTable($tableId) : [];

        return new FormListContext(
            pageId: (int) $page->getId(),
            pageKeyword: (string) $page->getKeyword(),
            config: $this->withTableColumns($config, $tableId),
            tableId: $tableId,
            userId: $user !== null ? (int) $user->getId() : null,
            languageId: $languageId,
            locale: $locale,
            timezone: $user?->getTimezone()?->getLookupCode() ?? 'UTC',
            formSectionId: $formSectionId,
            optionalColumns: $optionalColumns,
            template: $withTemplate && $config->usesRowTemplate() ? $this->rowTemplate($effective, $sectionId, $languageId) : [],
            form: $form,
            source: $effective->source,
            routeParams: $routeParams,
            routeRequirements: $routeRequirements,
            templateDataConfig: $withTemplate && $config->usesRowTemplate() ? $this->rowTemplateDataConfig($effective, $sectionId) : null,
        );
    }

    /**
     * What the author stored as the data_config of the data view itself, as the effective page holds it:
     * the supporting values the layout of one record may read next to the record.
     */
    private function rowTemplateDataConfig(EffectivePage $effective, int $sectionId): mixed
    {
        $row = $effective->rowsOf($sectionId)[0] ?? [];

        return $row['data_config'] ?? null;
    }

    /**
     * The sections placed inside a data view: the layout of one record. They come
     * from the effective page tree (translated for the visitor's language), where the
     * data view keeps its children unrendered, and leave out the styles a read-only
     * view never holds (forms and lists).
     *
     * @return list<array<string, mixed>>
     */
    private function rowTemplate(EffectivePage $effective, int $sectionId, int $languageId): array
    {
        $sections = $effective->isPublished()
            ? $this->pageService->publishedSections($effective, $languageId)
            : $this->pageService->getPageSections($effective->pageId, $languageId);
        $node = $this->findSection($sections, $sectionId);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        return $this->withoutExcludedStyles($children);
    }

    /**
     * @param array<array-key, mixed> $sections
     * @return array<string, mixed>|null
     */
    private function findSection(array $sections, int $sectionId): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (is_numeric($section['id'] ?? null) && (int) $section['id'] === $sectionId) {
                return $this->stringKeyed($section);
            }
            $inChildren = is_array($section['children'] ?? null) ? $this->findSection($section['children'], $sectionId) : null;
            if ($inChildren !== null) {
                return $inChildren;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $sections
     * @return list<array<string, mixed>>
     */
    private function withoutExcludedStyles(array $sections): array
    {
        $kept = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $section = $this->stringKeyed($section);
            if (in_array($section['style_name'] ?? null, StyleNames::ROW_TEMPLATE_EXCLUDED_STYLE_NAMES, true)) {
                continue;
            }
            if (is_array($section['children'] ?? null)) {
                $section['children'] = $this->withoutExcludedStyles($section['children']);
            }
            $kept[] = $section;
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function stringKeyed(array $row): array
    {
        $keyed = [];
        foreach ($row as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * A list that names no columns shows the columns of its table (a list of the
     * user's own records; {@see FormListConfigReader} refuses this for shared
     * and public lists).
     */
    private function withTableColumns(FormListConfig $config, ?int $tableId): FormListConfig
    {
        if ($config->columns !== [] || $tableId === null) {
            return $config;
        }

        $labels = $this->dataService->getColumnDisplayLabels($tableId);
        $columns = [];
        foreach ($this->engine->columnsOf($tableId) as $key => $column) {
            $format = match ($column['type']) {
                LookupService::DATA_COL_VALUE_TYPES_NUMBER => FormListColumn::FORMAT_NUMBER,
                LookupService::DATA_COL_VALUE_TYPES_DATE => FormListColumn::FORMAT_DATE,
                LookupService::DATA_COL_VALUE_TYPES_DATETIME => FormListColumn::FORMAT_DATETIME,
                LookupService::DATA_COL_VALUE_TYPES_BOOLEAN => FormListColumn::FORMAT_BOOLEAN,
                default => FormListColumn::FORMAT_TEXT,
            };
            $columns[] = new FormListColumn(
                $key,
                $labels[$key] ?? $key,
                true,
                $column['type'] === LookupService::DATA_COL_VALUE_TYPES_TEXT,
                $format,
            );
            if (count($columns) >= FormListConfig::MAX_COLUMNS) {
                break;
            }
        }

        return $config->withColumns($columns);
    }

    /**
     * Two lists on one page cannot share a key: the URL could not tell them apart.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    private function assertKeyIsUnique(FormListConfig $config, EffectivePage $effective, array $rows): void
    {
        $others = array_values(array_filter(
            FormListLayout::listSectionIds($rows),
            static fn (int $id): bool => $id !== $config->sectionId,
        ));
        foreach ($this->configReader->listKeysIn($effective, $others) as $key) {
            if ($key === $config->key) {
                throw new FormListConfigException(
                    [['field' => 'list_key', 'message' => 'Another list on this page uses the same key.']],
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                );
            }
        }
    }

    private function localeOf(int $languageId): string
    {
        $locale = $this->entityManager->getConnection()->fetchOne(
            'SELECT locale FROM languages WHERE id = :id',
            ['id' => $languageId],
            ['id' => ParameterType::INTEGER],
        );

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * An entry-record section as a read-only list context for current-file
     * delivery (contract 1): same page ACL, table, owner scope and typed
     * {@see FormListConfig::$fixedFilter} as the detail page shows (F166).
     *
     * @param array<string, string> $routeParams
     * @param array<string, string> $routeRequirements
     */
    private function entryRecordContext(
        Page $page,
        EffectivePage $effective,
        int $sectionId,
        ?int $languageId,
        array $routeParams,
        array $routeRequirements,
    ): FormListContext {
        $languageId = $this->pageService->determineLanguageId($languageId);
        $locale = $this->localeOf($languageId);
        $content = $this->fieldContent($effective->rawFields($sectionId));

        $tableText = $content('data_table');
        $tableId = ctype_digit($tableText) ? (int) $tableText : null;
        if ($tableId === null || $tableId <= 0) {
            throw new \App\Exception\ServiceException('The section is not a list.', Response::HTTP_BAD_REQUEST, ['reason' => 'not_a_list']);
        }
        $ownEntriesOnly = in_array($content('own_entries_only'), ['1', 'true', 'yes'], true);
        $fixedFilter = $this->withResolvedColumns($this->parseFixedFilterJson($content('fixed_filter')), $tableId);

        $config = new FormListConfig(
            sectionId: $sectionId,
            style: StyleNames::STYLE_ENTRY_RECORD,
            key: 'record',
            dataTableId: $tableId,
            ownEntriesOnly: $ownEntriesOnly,
            allowCreate: false,
            allowUpdate: false,
            allowDelete: false,
            layout: FormListConfig::LAYOUT_TABLE,
            formPresentation: FormListConfig::PRESENTATION_MODAL,
            pageSize: 1,
            columns: [],
            defaultSorts: [],
            controls: [],
            fixedFilter: $fixedFilter,
            quickActions: [],
            showHistory: false,
            detailUrl: null,
            labels: [],
        );

        return $this->readOnlyContext(
            $page,
            $config,
            $tableId,
            $languageId,
            $locale,
            $this->entryChildrenTemplate($effective, $sectionId, $languageId),
            $effective->source,
            $routeParams,
            $routeRequirements,
        );
    }

    /**
     * An entry-list section as a read-only list context for current-file
     * delivery: the same table, owner scope and resolved author `filter` the
     * page applies when it draws the cards (contract 1 amendment).
     *
     * @param array<string, string> $routeParams
     * @param array<string, string> $routeRequirements
     */
    private function entryListContext(
        Page $page,
        EffectivePage $effective,
        int $sectionId,
        ?int $languageId,
        array $routeParams,
        array $routeRequirements,
    ): FormListContext {
        $languageId = $this->pageService->determineLanguageId($languageId);
        $locale = $this->localeOf($languageId);
        $content = $this->fieldContent($effective->rawFields($sectionId));

        $tableText = $content('data_table');
        $tableId = ctype_digit($tableText) ? (int) $tableText : null;
        if ($tableId === null || $tableId <= 0) {
            throw new \App\Exception\ServiceException('The section is not a list.', Response::HTTP_BAD_REQUEST, ['reason' => 'not_a_list']);
        }
        $ownEntriesOnly = in_array($content('own_entries_only'), ['1', 'true', 'yes'], true);
        $fixedFilter = $this->withResolvedColumns(
            $this->resolvedEntryListFilter($content('filter'), $routeParams, $routeRequirements),
            $tableId,
        );

        $config = new FormListConfig(
            sectionId: $sectionId,
            style: StyleNames::STYLE_ENTRY_LIST,
            key: 'list',
            dataTableId: $tableId,
            ownEntriesOnly: $ownEntriesOnly,
            allowCreate: false,
            allowUpdate: false,
            allowDelete: false,
            layout: FormListConfig::LAYOUT_TABLE,
            formPresentation: FormListConfig::PRESENTATION_MODAL,
            pageSize: 25,
            columns: [],
            defaultSorts: [],
            controls: [],
            fixedFilter: $fixedFilter,
            quickActions: [],
            showHistory: false,
            detailUrl: null,
            labels: [],
        );

        return $this->readOnlyContext(
            $page,
            $config,
            $tableId,
            $languageId,
            $locale,
            $this->entryChildrenTemplate($effective, $sectionId, $languageId),
            $effective->source,
            $routeParams,
            $routeRequirements,
        );
    }

    /**
     * Children of an entry-list / entry-record as the author stored them (with
     * `{{field__url}}` tokens intact). {@see self::rowTemplate()} goes through
     * page rendering, which interpolates those tokens away when the record is
     * not in scope — useless for the "does the template reference this field?"
     * check.
     *
     * @return list<array<string, mixed>>
     */
    private function entryChildrenTemplate(EffectivePage $effective, int $sectionId, int $languageId): array
    {
        if ($effective->isPublished()) {
            // Published JSON still holds the author tokens (data-view style).
            return $this->rowTemplate($effective, $sectionId, $languageId);
        }

        $childIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id_child_section FROM rel_sections_hierarchy WHERE id_parent_section = :p ORDER BY position',
            ['p' => $sectionId],
            ['p' => ParameterType::INTEGER],
        );
        $template = [];
        foreach ($childIds as $childId) {
            if (!is_numeric($childId)) {
                continue;
            }
            $id = (int) $childId;
            if ($id <= 0) {
                continue;
            }
            $raw = $effective->rawFields($id);
            $fields = [];
            foreach ($raw as $name => $byLanguage) {
                if ($byLanguage === []) {
                    $fields[(string) $name] = ['content' => ''];
                    continue;
                }
                $fallbackKey = array_key_first($byLanguage);
                $content = $byLanguage[$languageId] ?? $byLanguage[1] ?? $byLanguage[$fallbackKey];
                $fields[(string) $name] = ['content' => $content];
            }
            $template[] = [
                'id' => $id,
                'fields' => $fields,
                'children' => $this->entryChildrenTemplate($effective, $id, $languageId),
            ];
        }

        return $template;
    }

    /**
     * Property-language content of a section field (language 1), matching
     * {@see FormListConfigReader} for draft and published pages.
     *
     * @param array<string, array<int, string>> $raw
     * @return \Closure(string): string
     */
    private function fieldContent(array $raw): \Closure
    {
        return static function (string $field) use ($raw): string {
            $stored = $raw[$field] ?? [];
            $content = $stored[1] ?? ($stored === [] ? null : $stored[array_key_first($stored)]);

            return ($content === null || $content === '') ? '' : trim($content);
        };
    }

    /**
     * Typed JSON fixed filter of an entry-record (same shape as list_fixed_filter).
     * Empty or missing means no extra bound. Invalid JSON refuses every record
     * (a filter that never matches), so a broken author filter never opens files.
     */
    private function parseFixedFilterJson(string $text): DataFilterGroup
    {
        if ($text === '') {
            return new DataFilterGroup();
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $this->impossibleFilter();
            }

            return DataFilterJson::parse($decoded);
        } catch (\JsonException|DataQueryException) {
            return $this->impossibleFilter();
        }
    }

    /**
     * The entry-list author filter after the same route interpolation
     * {@see PageService::resolveEntryRows} uses, as a structured fixed filter.
     *
     * @param array<string, string> $routeParams
     * @param array<string, string> $routeRequirements
     */
    private function resolvedEntryListFilter(string $rawFilter, array $routeParams, array $routeRequirements): DataFilterGroup
    {
        if ($rawFilter === '') {
            return new DataFilterGroup();
        }
        $prepared = $this->dataTableFilterService->prepareFilter(
            $rawFilter,
            ['route' => $routeParams, 'route_requirements' => $routeRequirements],
            $routeRequirements,
        );
        if ($prepared === '') {
            // Unresolved / unsafe: the page shows no rows; the file route matches none.
            return $this->impossibleFilter();
        }
        try {
            return $this->legacyFilterTranslator->translate($prepared)->filter;
        } catch (DataQueryException) {
            return $this->impossibleFilter();
        }
    }

    /** A filter that can never match a real record (fail-closed boundary). */
    private function impossibleFilter(): DataFilterGroup
    {
        return new DataFilterGroup(DataFilterGroup::MODE_AND, [
            new DataFilter('record_id', DataFilter::OP_EQ, 0),
        ]);
    }

    /**
     * Authors write input names (`status`); the engine needs field keys
     * (`section_123`). Same rewrite FormListPortability applies on import.
     */
    private function withResolvedColumns(DataFilterGroup $group, int $tableId): DataFilterGroup
    {
        if ($group->isEmpty()) {
            return $group;
        }
        $table = $this->dataService->getDataTableById($tableId);
        $tableName = $table !== null ? (string) $table->getName() : '';
        if ($tableName === '') {
            return $group;
        }
        $nameToKey = $this->keyResolver->getNameToFieldKey($tableName);
        if ($nameToKey === []) {
            return $group;
        }
        $map = static fn (string $name): string => $nameToKey[$name] ?? $name;
        $rewritten = FormListColumnReferences::rewrite(
            FormListColumnReferences::FIELD_FIXED_FILTER,
            json_encode(DataFilterJson::toArray($group), JSON_THROW_ON_ERROR),
            $map,
        );
        if ($rewritten === null) {
            return $group;
        }
        try {
            $decoded = json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? DataFilterJson::parse($decoded) : $group;
        } catch (\JsonException|DataQueryException) {
            return $group;
        }
    }

    /**
     * @param list<array<string, mixed>> $template
     * @param array<string, string> $routeParams
     * @param array<string, string> $routeRequirements
     */
    private function readOnlyContext(
        Page $page,
        FormListConfig $config,
        int $tableId,
        int $languageId,
        string $locale,
        array $template,
        string $source,
        array $routeParams,
        array $routeRequirements,
    ): FormListContext {
        $user = $this->userContext->getCurrentUser();

        return new FormListContext(
            pageId: (int) $page->getId(),
            pageKeyword: (string) $page->getKeyword(),
            config: $this->withTableColumns($config, $tableId),
            tableId: $tableId,
            userId: $user !== null ? (int) $user->getId() : null,
            languageId: $languageId,
            locale: $locale,
            timezone: $user?->getTimezone()?->getLookupCode() ?? 'UTC',
            formSectionId: null,
            optionalColumns: $this->optionalColumnsForTable($tableId),
            template: $template,
            form: null,
            source: $source,
            routeParams: $routeParams,
            routeRequirements: $routeRequirements,
            templateDataConfig: null,
        );
    }

    /**
     * Field keys the form of this table declares, with their value types.
     * Used as {@see DataQuery::$optionalColumns} so a filter/sort/column on a
     * declared field that has no `data_cols` row yet reads as empty (F169)
     * instead of `Unknown column`. A name the form does not declare stays out
     * and is still refused by the engine.
     *
     * @return array<string, string>
     */
    private function optionalColumnsForTable(int $tableId): array
    {
        $table = $this->dataService->getDataTableById($tableId);
        $tableName = $table !== null ? (string) $table->getName() : '';
        if ($tableName === '' || !ctype_digit($tableName)) {
            return [];
        }
        $types = $this->keyResolver->getFieldKeyToValueType($tableName);
        $optional = [];
        foreach (array_keys($this->keyResolver->getFieldKeyToName($tableName)) as $key) {
            $optional[$key] = $types[$key] ?? LookupService::DATA_COL_VALUE_TYPES_TEXT;
        }

        return $optional;
    }

}
