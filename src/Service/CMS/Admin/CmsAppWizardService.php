<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\CmsApp;
use App\Entity\Language;
use App\Entity\Section;
use App\Exception\ServiceException;
use App\Repository\CmsAppRepository;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Routing\RouteConflictValidator;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scaffold the pages of an app into an existing first-class {@see CmsApp}.
 *
 * One logical dataset, two surfaces, no duplicated input definition:
 *
 *  - the CMS surface (`/cms/<base>`, role `cms_list`) is one `entry-form-list`.
 *    Its inline form is the only place the inputs are defined; the form owns the
 *    data table. The list creates, edits, deletes and (with history on) restores
 *    records, so the app needs neither a separate form page nor a separate CMS
 *    detail page;
 *  - the public surface (`/<base>`, role `public_list`) is a read-only
 *    `data-view` of the same table, linking each row to the public detail page
 *    (`/<base>/{record_id}`, role `public_detail`), an `entry-record`.
 *
 * The wizard writes every page through the same section import a bundle goes
 * through, so the list configuration is checked by the same validator. Every
 * created page is assigned to the target app with a strict {@see CmsAppRole};
 * hub FKs are refreshed via {@see CmsAppHubSyncService} (never written here).
 */
class CmsAppWizardService extends BaseService
{
    /**
     * Input styles the wizard's form-field builder may scaffold. Restricted to
     * the simple, label-only inputs that need no extra config to be usable right
     * after generation (the editor can swap/extend them afterwards). There is no
     * dedicated date input style in the catalog yet, so dates use `text-input`.
     */
    private const FORM_FIELD_STYLES = [
        'text-input',
        'textarea',
        'select',
        'checkbox',
        'radio',
        'number-input',
        // Field types that scaffold a file-input (contract 5/6 of the media brief).
        'image',
        'files',
        'file-input',
    ];

    private const STATUS_FILTER = '{"mode":"and","children":[{"column":"status","operator":"eq","value":"published"}]}';

    private const STATUS_QUICK_ACTIONS = '['
        . '{"id":"publish","label":"Publish","set":{"status":"published"},'
        . '"when":{"mode":"and","children":[{"column":"status","operator":"eq","value":"draft"}]}},'
        . '{"id":"unpublish","label":"Unpublish","set":{"status":"draft"},'
        . '"when":{"mode":"and","children":[{"column":"status","operator":"eq","value":"published"}]}}'
        . ']';

    private const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';

    /** The most columns a list may show (the list validator's limit). */
    private const MAX_LIST_COLUMNS = 30;

    /** Columns of the public view: a card or a row stays readable. */
    private const MAX_PUBLIC_COLUMNS = 6;

    /** Rows per page of the public view. */
    private const PUBLIC_PAGE_SIZE = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly CmsAppRepository $cmsAppRepository,
        private readonly DataService $dataService,
        private readonly DataTableService $dataTableService,
        private readonly DataQueryEngine $queryEngine,
        private readonly FormFieldKeyResolver $keyResolver,
        private readonly RouteConflictValidator $conflictValidator,
        private readonly AdminPageService $adminPageService,
        private readonly SectionExportImportService $sectionExportImportService,
        private readonly CmsAppHubSyncService $hubSyncService,
    ) {
    }

    /**
     * Scaffold pages into an existing CMS app (a CMS list and/or a public list+detail pair).
     *
     * @param array<string, mixed> $input
     * @return array{app_id: int, created: list<array{keyword: string, page_id: int, surface: string, role: string}>}
     */
    public function scaffoldCmsApp(int $appId, array $input): array
    {
        $app = $this->cmsAppRepository->find($appId);
        if (!$app instanceof CmsApp) {
            $this->throwNotFound(sprintf('CMS app %d not found.', $appId));
        }

        $baseName = strtolower(trim($this->asString($input['base_name'] ?? '')));
        if ($baseName === '') {
            $baseName = $app->getSlug();
        }
        if ($baseName === '' || !preg_match('/^[a-z0-9-]+$/', $baseName)) {
            $this->throwBadRequest('base_name is required and may only contain lowercase letters, numbers and hyphens.');
        }

        $createForm = (bool) ($input['create_form'] ?? false);

        // With create_form the wizard scaffolds the inline form of the CMS list; it
        // OWNS a fresh data table (named by the new form section id), so no
        // pre-existing table is needed. Without it, bind to an existing table.
        $dataTableName = $this->asString($input['data_table'] ?? '');
        if (!$createForm) {
            if ($dataTableName === '' || $this->dataService->getDataTableByName($dataTableName) === null) {
                $this->throwBadRequest(sprintf('Data table "%s" does not exist.', $dataTableName));
            }
        }

        $createPublic = !array_key_exists('create_public', $input) || (bool) $input['create_public'];
        $createAdmin = !array_key_exists('create_admin', $input) || (bool) $input['create_admin'];
        if (!$createPublic && !$createAdmin) {
            $this->throwBadRequest('At least one of create_public / create_admin must be enabled.');
        }
        if ($createForm && !$createAdmin) {
            $this->throwBadRequest('create_form needs create_admin: the form lives inside the CMS list that manages the records.');
        }

        $recordParam = $this->asString($input['record_id_param'] ?? 'record_id');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $recordParam)) {
            $this->throwBadRequest('record_id_param must be a snake_case identifier.');
        }

        $formFieldName = $this->asString($input['form_field_name'] ?? 'title');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $formFieldName)) {
            $this->throwBadRequest('form_field_name must be a snake_case identifier.');
        }
        $formFieldLabel = $this->asString($input['form_field_label'] ?? '') ?: ucfirst(str_replace('_', ' ', $formFieldName));

        // Multi-field builder: a normalized list of {name, style, label, translatable}.
        // Falls back to the single legacy field so existing callers are unaffected.
        $formFields = $this->parseFormFields($input['form_fields'] ?? null, $formFieldName, $formFieldLabel);

        // Draft/published status field (default on). Editors prepare as draft,
        // then publish; the public list/detail only show published records.
        $withStatus = !array_key_exists('with_status', $input) || (bool) $input['with_status'];
        if ($withStatus && $createForm) {
            $names = array_column($formFields, 'name');
            if (!in_array('status', $names, true)) {
                $formFields[] = [
                    'name' => 'status',
                    'style' => 'select',
                    'label' => 'Status',
                    'translatable' => false,
                    'kind' => 'status',
                ];
            }
        }

        $listTitle = $this->asString($input['list_title'] ?? '') ?: ucfirst($baseName);
        $detailTitle = $this->asString($input['detail_title'] ?? '') ?: ($listTitle . ' detail');
        $accessGroups = $this->intList($input['access_groups'] ?? []);

        // Build the intended page set (keyword, url, surface, role, route). The
        // CMS page (if any) comes FIRST: its inline form owns the table, and
        // the public pages bind to that table by its new id.
        $plan = [];
        if ($createAdmin) {
            $plan[] = $this->pagePlan('cms-' . $baseName, '/cms/' . $baseName, LookupService::PAGE_SURFACE_CMS, CmsAppRole::CMS_LIST, false, $recordParam);
        }
        if ($createPublic) {
            $plan[] = $this->pagePlan($baseName, '/' . $baseName, LookupService::PAGE_SURFACE_PUBLIC, CmsAppRole::PUBLIC_LIST, false, $recordParam);
            $plan[] = $this->pagePlan($baseName . '-record', '/' . $baseName . '/{' . $recordParam . '}', LookupService::PAGE_SURFACE_PUBLIC, CmsAppRole::PUBLIC_DETAIL, true, $recordParam);
        }

        // Reject scaffold if the app already holds any of the primary roles we
        // are about to create (keeps uniqueness without partial assign).
        foreach ($plan as $entry) {
            if (CmsAppRole::isPrimary($entry['role'])) {
                $existing = $this->pageRepository->findOneBy([
                    'cmsApp' => $app,
                    'cmsAppRole' => $entry['role'],
                ]);
                if ($existing !== null) {
                    $this->throwConflict(sprintf(
                        'CMS app "%s" already has a page with role "%s".',
                        $app->getSlug(),
                        $entry['role']
                    ));
                }
            }
        }

        $this->assertNoConflicts($plan);

        [$contentLocales, $propertyLocale] = $this->resolveLocales();

        $created = [];

        // True atomicity: the wizard creates several pages, each with its own
        // route + section import through services that manage their own (now
        // nested) transactions. Nested begin/commit/rollback become savepoints
        // of this single outer transaction (DBAL 4+ default nesting), so ANY
        // failure rolls the whole app creation back: no orphaned pages, routes,
        // or sections.
        $this->entityManager->beginTransaction();
        try {
            foreach ($plan as $entry) {
                $page = $this->adminPageService->createPage(
                    $entry['keyword'],
                    LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
                    false,
                    false,
                    $entry['url'],
                    null,
                    $entry['surface'],
                    $accessGroups,
                    null,
                    [[
                        'path_pattern' => $entry['path_pattern'],
                        'requirements' => $entry['requirements'],
                        'is_canonical' => true,
                        'is_active' => true,
                        'priority' => 0,
                    ]],
                );
                $pageId = (int) $page->getId();
                $page->setCmsApp($app);
                $page->setCmsAppRole($entry['role']);
                $this->entityManager->flush();

                $created[] = [
                    'keyword' => $entry['keyword'],
                    'page_id' => $pageId,
                    'surface' => $entry['surface'],
                    'role' => $entry['role'],
                ];

                if ($entry['role'] === CmsAppRole::CMS_LIST) {
                    if ($createForm) {
                        // The list and its inline form go in together; the form's
                        // table is then materialized, so the public pages bind to it.
                        $formSectionId = $this->scaffoldListAndFormTable(
                            $pageId,
                            $baseName,
                            $listTitle,
                            $formFields,
                            $withStatus,
                            $contentLocales,
                            $propertyLocale
                        );
                        $dataTableName = (string) $formSectionId;
                    } else {
                        // An existing table has no form to write it: the CMS list reads it and can delete.
                        $sections = $this->buildCmsListSections(
                            $baseName,
                            $listTitle,
                            $this->tableColumns($dataTableName),
                            [],
                            $this->resolveDataTableId($dataTableName),
                            false,
                            false,
                            $contentLocales,
                            $propertyLocale
                        );
                        $this->sectionExportImportService->importSectionsToPage($pageId, $sections);
                    }
                    continue;
                }

                if ($entry['is_detail']) {
                    $sections = $this->buildDetailSections(
                        $baseName,
                        $dataTableName,
                        $recordParam,
                        $detailTitle,
                        $formFields,
                        $withStatus,
                        $contentLocales,
                        $propertyLocale
                    );
                } else {
                    // Public list = read-only data view of the SAME table, with a
                    // link to the public detail page on every row.
                    $sections = $this->buildPublicListSections(
                        $baseName,
                        $dataTableName,
                        $listTitle,
                        $createForm ? $this->formColumns($formFields) : $this->tableColumns($dataTableName),
                        $withStatus,
                        $contentLocales,
                        $propertyLocale
                    );
                }

                $this->sectionExportImportService->importSectionsToPage($pageId, $sections);
            }

            // Single hub writer — after all pages carry roles.
            $this->hubSyncService->sync($app);

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e instanceof ServiceException ? $e : new ServiceException(
                'CMS app scaffold failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        }

        return [
            'app_id' => (int) $app->getId(),
            'created' => $created,
        ];
    }

    /**
     * Scaffold the CMS list that is also the form (inputs directly inside it),
     * locate the list section and materialize its owned data table. Returns the
     * list section id: it doubles as the data table name the public pages bind to.
     *
     * @param list<array{name: string, style: string, label: string, kind?: string}> $formFields
     * @param list<string> $contentLocales
     */
    private function scaffoldListAndFormTable(
        int $pageId,
        string $baseName,
        string $listTitle,
        array $formFields,
        bool $withStatus,
        array $contentLocales,
        string $propertyLocale
    ): int {
        $sections = $this->buildCmsListSections(
            $baseName,
            $listTitle,
            $this->formColumns($formFields),
            $formFields,
            null,
            true,
            $withStatus,
            $contentLocales,
            $propertyLocale
        );
        $imported = $this->sectionExportImportService->importSectionsToPage($pageId, $sections);

        $listSectionName = $baseName . '-cms-list';
        $listSectionId = null;
        foreach ($imported as $importedSection) {
            if ($this->asString($importedSection['source_name'] ?? '') === $listSectionName && is_numeric($importedSection['id'] ?? null)) {
                $listSectionId = (int) $importedSection['id'];
                break;
            }
        }
        if ($listSectionId === null) {
            throw new ServiceException('CMS app creation failed: the scaffolded list section could not be located.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $listSection = $this->sectionRepository->find($listSectionId);
        if (!$listSection instanceof Section) {
            throw new ServiceException('CMS app creation failed: the scaffolded list section could not be loaded.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Owned table is named by the list section id; create it now so the
        // public bindings resolve before the first submission.
        $this->dataTableService->createDataTableForFormSection($listSection);

        return $listSectionId;
    }

    /**
     * Resolve the numeric `data_tables.id` for a table name (a list binds its
     * table by id).
     */
    private function resolveDataTableId(string $dataTableName): int
    {
        $dataTable = $this->dataService->getDataTableByName($dataTableName);
        if ($dataTable === null) {
            throw new ServiceException(
                sprintf('CMS app creation failed: data table "%s" could not be resolved for the list.', $dataTableName),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return (int) $dataTable->getId();
    }

    /**
     * @return array{keyword: string, url: string, surface: string, role: string, is_detail: bool, path_pattern: string, requirements: array<string, string>|null}
     */
    private function pagePlan(string $keyword, string $url, string $surface, string $role, bool $isDetail, string $recordParam): array
    {
        return [
            'keyword' => $keyword,
            'url' => $url,
            'surface' => $surface,
            'role' => $role,
            'is_detail' => $isDetail,
            'path_pattern' => $url,
            'requirements' => $isDetail ? [$recordParam => '\\d+'] : null,
        ];
    }

    /**
     * Reject the wizard if any intended keyword already exists or the intended
     * active route set conflicts with existing routes or itself.
     *
     * @param list<array{keyword: string, url: string, surface: string, role: string, is_detail: bool, path_pattern: string, requirements: array<string, string>|null}> $plan
     */
    private function assertNoConflicts(array $plan): void
    {
        foreach ($plan as $entry) {
            if ($this->pageRepository->findOneBy(['keyword' => $entry['keyword']]) !== null) {
                $this->throwConflict(sprintf('A page with keyword "%s" already exists.', $entry['keyword']));
            }
            if ($this->pageRepository->findOneBy(['url' => $entry['url']]) !== null) {
                $this->throwConflict(sprintf('A page with url "%s" already exists.', $entry['url']));
            }
        }

        $patterns = [];
        foreach ($plan as $entry) {
            $patterns[] = ['path_pattern' => $entry['path_pattern']];
        }

        $conflicts = $this->conflictValidator->findConflictsForSet($patterns, null);
        if ($conflicts !== []) {
            $messages = [];
            foreach ($conflicts as $conflict) {
                $messages[] = $conflict['message'];
            }
            $this->throwConflict('Route conflict: ' . implode('; ', $messages));
        }
    }

    /**
     * Resolve the content locales (real languages only) and the property locale
     * ({@see Language::INDEPENDENT_LOCALE}, used for non-translatable fields like a link URL).
     *
     * @return array{0: list<string>, 1: string}
     */
    private function resolveLocales(): array
    {
        /** @var list<Language> $languages */
        $languages = $this->entityManager->getRepository(Language::class)->findAll();

        $contentLocales = [];
        $propertyLocale = 'all';
        foreach ($languages as $language) {
            $locale = $language->getLocale();
            if ($locale === null || $locale === '') {
                continue;
            }
            if ((int) $language->getId() === Language::INDEPENDENT_ID) {
                $propertyLocale = $locale;
                continue;
            }
            $contentLocales[] = $locale;
        }

        if ($contentLocales === []) {
            // Single-language install: fall back to the property locale so the
            // generated copy is still stored somewhere editable.
            $contentLocales[] = $propertyLocale;
        }

        return [$contentLocales, $propertyLocale];
    }

    /**
     * Normalize the requested form fields into a validated list of
     * {name, style, label, kind?}. When the caller sends no `form_fields`, fall
     * back to the single legacy field so older callers keep working unchanged.
     * Styles `image` and `files` scaffold a `file-input` with the right accept /
     * multiple / files_public flags.
     *
     * @param mixed $raw
     * @return list<array{name: string, style: string, label: string, translatable: bool, kind?: string}>
     */
    private function parseFormFields(mixed $raw, string $legacyName, string $legacyLabel): array
    {
        $fields = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_array($entry)) {
                    $this->throwBadRequest('Each form field must be an object with a name and style.');
                }
                $name = strtolower(trim($this->asString($entry['name'] ?? '')));
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                    $this->throwBadRequest(sprintf('Form field name "%s" must be a snake_case identifier.', $name));
                }
                $style = $this->asString($entry['style'] ?? 'text-input') ?: 'text-input';
                if (!in_array($style, self::FORM_FIELD_STYLES, true)) {
                    $this->throwBadRequest(sprintf('Form field style "%s" is not supported.', $style));
                }
                $label = $this->asString($entry['label'] ?? '') ?: ucfirst(str_replace('_', ' ', $name));
                $translatable = ($entry['translatable'] ?? false) === true;
                if ($translatable && !in_array($style, ['text-input', 'textarea'], true)) {
                    $this->throwBadRequest(sprintf(
                        'Form field "%s" of type "%s" cannot be translatable.',
                        $name,
                        $style
                    ));
                }
                $kind = match ($style) {
                    'image' => 'image',
                    'files' => 'files',
                    default => null,
                };
                $field = [
                    'name' => $name,
                    'style' => in_array($style, ['image', 'files'], true) ? 'file-input' : $style,
                    'label' => $label,
                    'translatable' => $translatable,
                ];
                if ($kind !== null) {
                    $field['kind'] = $kind;
                }
                $fields[] = $field;
            }
        }

        if ($fields === []) {
            $fields[] = ['name' => $legacyName, 'style' => 'text-input', 'label' => $legacyLabel, 'translatable' => false];
        }

        $names = array_column($fields, 'name');
        if (count($names) !== count(array_unique($names))) {
            $this->throwBadRequest('Form field names must be unique.');
        }
        if (count($fields) > self::MAX_LIST_COLUMNS) {
            $this->throwBadRequest(sprintf('A list shows at most %d fields.', self::MAX_LIST_COLUMNS));
        }

        return $fields;
    }

    /**
     * The URL namespace of the lists of an app: the base name as a list key
     * (`^[a-z][a-z0-9_]{0,31}$`).
     */
    private function listKeyFromBaseName(string $baseName): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $baseName), '_');
        if ($key === '' || !ctype_alpha($key[0])) {
            $key = 'l_' . $key;
        }

        return substr($key, 0, 32);
    }

    /**
     * The columns of a list that shows the fields of the scaffolded form: one
     * per input, named by the input (the import turns the names into the keys of
     * the new inputs), in the order of the form.
     *
     * @param list<array{name: string, style: string, label: string, kind?: string}> $formFields
     * @return list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}>
     */
    private function formColumns(array $formFields): array
    {
        $columns = [];
        foreach ($formFields as $field) {
            $kind = $field['kind'] ?? null;
            $format = match (true) {
                $kind === 'image' || ($field['style'] === 'file-input' && $kind !== 'files') => 'image',
                $kind === 'files' || $field['style'] === 'file-input' => 'file',
                ($field['kind'] ?? null) === 'status' => 'badge',
                $field['style'] === 'number-input' => 'number',
                $field['style'] === 'checkbox' => 'boolean',
                $field['name'] === 'status' && $field['style'] === 'select' => 'badge',
                default => null,
            };
            $columns[] = [
                'field' => $field['name'],
                'label' => $field['label'],
                'sortable' => !in_array($field['style'], ['file-input'], true),
                'searchable' => in_array($field['style'], ['text-input', 'textarea'], true),
                'format' => $format,
                'choice' => in_array($field['style'], ['select', 'radio'], true),
            ];
        }

        return $columns;
    }

    /**
     * The columns of a list over an existing table: every stored column, named by
     * its key; the input name is the label when the table belongs to a form.
     *
     * @return list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}>
     */
    private function tableColumns(string $dataTableName): array
    {
        $table = $this->dataService->getDataTableByName($dataTableName);
        if ($table === null) {
            return [];
        }

        $names = ctype_digit($dataTableName) ? $this->keyResolver->getFieldKeyToName($dataTableName) : [];
        $columns = [];
        foreach ($this->queryEngine->columnsOf((int) $table->getId()) as $key => $column) {
            $columns[] = [
                'field' => $key,
                'label' => $names[$key] ?? $key,
                'sortable' => true,
                'searchable' => $column['type'] === LookupService::DATA_COL_VALUE_TYPES_TEXT,
                'format' => match ($column['type']) {
                    LookupService::DATA_COL_VALUE_TYPES_NUMBER => 'number',
                    LookupService::DATA_COL_VALUE_TYPES_DATE => 'date',
                    LookupService::DATA_COL_VALUE_TYPES_DATETIME => 'datetime',
                    LookupService::DATA_COL_VALUE_TYPES_BOOLEAN => 'boolean',
                    default => null,
                },
                'choice' => false,
            ];
            if (count($columns) >= self::MAX_LIST_COLUMNS) {
                break;
            }
        }

        return $columns;
    }

    /**
     * The `list_columns` document of a list over these columns.
     *
     * @param list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}> $columns
     */
    private function columnsJson(array $columns, int $limit): string
    {
        $items = [];
        foreach (array_slice($columns, 0, $limit) as $column) {
            $item = ['field' => $column['field'], 'label' => $column['label']];
            if ($column['sortable']) {
                $item['sortable'] = true;
            }
            if ($column['searchable']) {
                $item['searchable'] = true;
            }
            if ($column['format'] !== null) {
                $item['format'] = $column['format'];
            }
            $items[] = $item;
        }

        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * The visitor controls of a list: a choice of the values of the first
     * select or radio input, counted inside the visitor's dataset.
     *
     * @param list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}> $columns
     */
    private function controlsJson(array $columns, int $limit): string
    {
        foreach (array_slice($columns, 0, $limit) as $column) {
            if ($column['choice']) {
                return json_encode([[
                    'id' => substr($column['field'], 0, 32),
                    'type' => 'select',
                    'field' => $column['field'],
                    'label' => $column['label'],
                    'options_from' => 'data',
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
        }

        return '[]';
    }

    /**
     * One editable interpolation line listing every field token (e.g.
     * "{{team_member.name}} {{team_member.email}}"). Row data is exposed under
     * the configured entry scope so templates stay explicit and searchable.
     *
     * @param list<array{name: string, style: string, label: string}> $formFields
     */
    private function fieldInterpolationBlock(array $formFields, string $scope): string
    {
        return implode(' ', array_map(
            static fn (array $field): string => '{{' . $scope . '.' . $field['name'] . '}}',
            $formFields,
        ));
    }

    /**
     * Derive a snake_case entry scope from the app base slug (e.g. team-members → team_member).
     */
    private function entryScopeFromBaseName(string $baseName): string
    {
        $parts = explode('-', $baseName);
        $last = (string) array_pop($parts);
        if ($last === '') {
            $last = $baseName;
        }
        if (str_ends_with($last, 'ies') && strlen($last) > 3) {
            $last = substr($last, 0, -3) . 'y';
        } elseif (str_ends_with($last, 's') && strlen($last) > 1 && !str_ends_with($last, 'ss')) {
            $last = substr($last, 0, -1);
        }
        $parts[] = $last;

        return implode('_', $parts);
    }

    private function entryRecordRouteParam(string $recordParam): string
    {
        return $recordParam !== '' ? $recordParam : 'record_id';
    }

    /**
     * The CMS surface: ONE `entry-form-list` that is also the form. It shows
     * every record, adds, edits and deletes them in a dialog, offers history,
     * and holds the inputs as its children. Without a form (an existing table)
     * the list reads the table by id and can only delete and run quick actions.
     *
     * @param list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}> $columns
     * @param list<array{name: string, style: string, label: string, kind?: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildCmsListSections(
        string $baseName,
        string $listTitle,
        array $columns,
        array $formFields,
        ?int $dataTableId,
        bool $withForm,
        bool $withStatus,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $fields = [
            'title' => $this->contentField($listTitle, $contentLocales),
            'list_key' => $this->propertyField($this->listKeyFromBaseName($baseName), $propertyLocale),
            'list_layout' => $this->propertyField('table', $propertyLocale),
            'form_presentation' => $this->propertyField('modal', $propertyLocale),
            'page_size' => $this->propertyField('25', $propertyLocale),
            'list_columns' => $this->propertyField($this->columnsJson($columns, self::MAX_LIST_COLUMNS), $propertyLocale),
            'list_controls' => $this->propertyField($this->controlsJson($columns, self::MAX_LIST_COLUMNS), $propertyLocale),
            // An editorial list shows every record to the people who may open the page.
            'own_entries_only' => $this->propertyField('0', $propertyLocale),
            'allow_create' => $this->propertyField($withForm ? '1' : '0', $propertyLocale),
            'allow_update' => $this->propertyField($withForm ? '1' : '0', $propertyLocale),
            'allow_delete' => $this->propertyField('1', $propertyLocale),
            'show_history' => $this->propertyField($withForm ? '1' : '0', $propertyLocale),
        ];
        if ($withForm) {
            $fields['alert_success'] = $this->contentField('Saved.', $contentLocales);
        }
        if ($withStatus && $withForm) {
            $fields['quick_actions'] = $this->propertyField(self::STATUS_QUICK_ACTIONS, $propertyLocale);
        }
        if ($dataTableId !== null) {
            $fields['data_table'] = $this->propertyField((string) $dataTableId, $propertyLocale);
        }

        $section = [
            'section_name' => $baseName . '-cms-list',
            'style_name' => 'entry-form-list',
            'fields' => $fields,
        ];
        if ($withForm) {
            $section['children'] = $this->buildListInputChildren($baseName, $formFields, $contentLocales, $propertyLocale);
        }

        return [$section];
    }

    /**
     * The inputs of the list-as-form: one child section per field.
     *
     * @param list<array{name: string, style: string, label: string, translatable?: bool, kind?: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildListInputChildren(string $baseName, array $formFields, array $contentLocales, string $propertyLocale): array
    {
        $inputChildren = [];
        foreach ($formFields as $field) {
            $fields = [
                'name' => $this->propertyField($field['name'], $propertyLocale),
                'label' => $this->contentField($field['label'], $contentLocales),
            ];
            if (($field['translatable'] ?? false) === true) {
                $fields['translatable'] = $this->propertyField('1', $propertyLocale);
            }
            $kind = $field['kind'] ?? null;
            if ($field['style'] === 'select' && ($kind === 'status' || $field['name'] === 'status')) {
                $fields['options'] = $this->propertyField(
                    '[{"value":"draft","sort":1},{"value":"published","sort":2}]',
                    $propertyLocale
                );
                $fields['option_labels'] = $this->contentField(
                    '{"draft":"Draft","published":"Published"}',
                    $contentLocales
                );
                $fields['value'] = $this->propertyField('draft', $propertyLocale);
            }
            if ($field['style'] === 'file-input') {
                $fields['files_public'] = $this->propertyField('1', $propertyLocale);
                if ($kind === 'image' || $kind === null) {
                    $fields['web_file_input_accept'] = $this->propertyField(self::IMAGE_ACCEPT, $propertyLocale);
                }
                if ($kind === 'files') {
                    $fields['web_file_input_multiple'] = $this->propertyField('1', $propertyLocale);
                }
            }
            $inputChildren[] = [
                'section_name' => $baseName . '-form-' . $field['name'],
                'style_name' => $field['style'],
                'fields' => $fields,
            ];
        }

        return $inputChildren;
    }

    /**
     * The public list: a read-only `data-view` of the table as cards, with a
     * "Open" link to the public detail page on every row. The detail is a
     * normal, shareable full page (NOT a modal), so the public URL stays
     * linkable. Nothing here can write: a data view refuses every write.
     *
     * @param list<array{field: string, label: string, sortable: bool, searchable: bool, format: string|null, choice: bool}> $columns
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildPublicListSections(
        string $baseName,
        string $dataTableName,
        string $listTitle,
        array $columns,
        bool $withStatus,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $dataTableId = $this->resolveDataTableId($dataTableName);
        $viewFields = [
            'title' => $this->contentField($listTitle, $contentLocales),
            'list_key' => $this->propertyField($this->listKeyFromBaseName($baseName), $propertyLocale),
            'data_table' => $this->propertyField((string) $dataTableId, $propertyLocale),
            'list_layout' => $this->propertyField('cards', $propertyLocale),
            'page_size' => $this->propertyField((string) self::PUBLIC_PAGE_SIZE, $propertyLocale),
            'list_columns' => $this->propertyField($this->columnsJson($columns, self::MAX_PUBLIC_COLUMNS), $propertyLocale),
            'list_controls' => $this->propertyField($this->controlsJson($columns, self::MAX_PUBLIC_COLUMNS), $propertyLocale),
            'own_entries_only' => $this->propertyField('0', $propertyLocale),
            'detail_url' => $this->propertyField('/' . $baseName . '/{record_id}', $propertyLocale),
        ];
        if ($withStatus) {
            $viewFields['list_fixed_filter'] = $this->propertyField(self::STATUS_FILTER, $propertyLocale);
        }

        return [[
            'section_name' => $baseName . '-page',
            'style_name' => 'container',
            'fields' => [
                'size' => $this->propertyField('lg', $propertyLocale),
                'spacing' => $this->propertyField('{"pt":"xl","pb":"xl","ps":"md","pe":"md"}', $propertyLocale),
            ],
            'children' => [[
                'section_name' => $baseName . '-view',
                'style_name' => 'data-view',
                'fields' => $viewFields,
            ]],
        ]];
    }

    /**
     * @param list<array{name: string, style: string, label: string, translatable?: bool, kind?: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildDetailSections(
        string $baseName,
        string $dataTableName,
        string $recordParam,
        string $detailTitle,
        array $formFields,
        bool $withStatus,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $dataTableId = $this->resolveDataTableId($dataTableName);
        $scope = $this->entryScopeFromBaseName($baseName);
        $recordFields = [
            'data_table' => $this->propertyField((string) $dataTableId, $propertyLocale),
            'own_entries_only' => $this->propertyField('0', $propertyLocale),
            'load_record_from' => $this->propertyField($this->entryRecordRouteParam($recordParam), $propertyLocale),
            'scope' => $this->propertyField($scope, $propertyLocale),
        ];
        // F166: typed fixed_filter bounds the public detail (and its files).
        if ($withStatus) {
            $recordFields['fixed_filter'] = $this->propertyField(self::STATUS_FILTER, $propertyLocale);
        }

        $children = [
            [
                'section_name' => $baseName . '-detail-title',
                'style_name' => 'title',
                'fields' => [
                    'content' => $this->contentField($detailTitle, $contentLocales),
                ],
            ],
        ];
        $imageField = null;
        $filesField = null;
        $textFields = [];
        foreach ($formFields as $field) {
            $kind = $field['kind'] ?? null;
            if ($kind === 'image' || ($field['style'] === 'file-input' && $kind !== 'files' && $imageField === null)) {
                $imageField = $field;
                continue;
            }
            if ($kind === 'files') {
                $filesField = $field;
                continue;
            }
            if ($field['name'] === 'status') {
                continue;
            }
            $textFields[] = $field;
        }
        if ($imageField !== null) {
            $children[] = [
                'section_name' => $baseName . '-detail-image',
                'style_name' => 'image',
                'fields' => [
                    'img_src' => $this->contentField('{{' . $scope . '.' . $imageField['name'] . '__url}}', $contentLocales),
                    'alt' => $this->contentField($imageField['label'], $contentLocales),
                ],
            ];
        }
        $children[] = [
            'section_name' => $baseName . '-detail-fields',
            'style_name' => 'text',
            'fields' => [
                'text' => $this->contentField($this->fieldInterpolationBlock($textFields !== [] ? $textFields : $formFields, $scope), $contentLocales),
            ],
        ];
        if ($filesField !== null) {
            // `__links` is server-built HTML (`<a href>` list) for the existing
            // `text` style on web and mobile; `__files` stays for authors who need JSON.
            $children[] = [
                'section_name' => $baseName . '-detail-downloads',
                'style_name' => 'text',
                'fields' => [
                    'text' => $this->contentField('{{' . $scope . '.' . $filesField['name'] . '__links}}', $contentLocales),
                ],
            ];
        }

        return [[
            'section_name' => $baseName . '-detail',
            'style_name' => 'entry-record',
            'fields' => $recordFields,
            'children' => $children,
        ]];
    }

    /**
     * Translatable (display=1) field value, written for every content locale.
     *
     * @param list<string> $contentLocales
     * @return array<string, array{content: string}>
     */
    private function contentField(string $value, array $contentLocales): array
    {
        $entry = [];
        foreach ($contentLocales as $locale) {
            $entry[$locale] = ['content' => $value];
        }

        return $entry;
    }

    /**
     * Non-translatable (display=0) field value, written under the
     * language-independent property locale only.
     *
     * @return array<string, array{content: string}>
     */
    private function propertyField(string $value, string $propertyLocale): array
    {
        return [$propertyLocale => ['content' => $value]];
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $result[] = (int) $item;
            }
        }

        return $result;
    }
}
