<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Routing\RouteConflictValidator;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Create list + detail pages" CMS-in-CMS app wizard (issue #30, Phase 6).
 *
 * Atomically scaffolds a working list/detail pattern bound to a data table:
 * a public pair (`/<base>` list + `/<base>/{record_id}` detail) and/or an admin
 * CMS pair (`/cms/<base>` + `/cms/<base>/{record_id}`). For each page it creates
 * the page (with the right `page_surface` + ACL), its DB-driven `page_routes`,
 * and an `entry-list` / `entry-record` holder section whose `data_config` binds
 * to the table — the detail filtering on `record_id = {{route.record_id}}`.
 * With `create_form` the admin detail page instead ATTACHES the shared
 * `form-record` section (record edit mode): the list's per-row action opens an
 * edit form modal prefilled from the URL record id.
 *
 * Everything is conflict-checked up front (keywords + the full active route set)
 * so a partial failure is unlikely; any error still rolls back by deleting the
 * pages created so far. The generated pages are ordinary CMS pages, fully
 * editable afterwards.
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
    ];

    /** Page property field that makes a page render inside a web modal. */
    private const FIELD_OPEN_IN_MODAL = 'open_in_modal';

    /** Internal language id used for non-translatable page property fields. */
    private const PROPERTY_LANGUAGE_ID = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly DataService $dataService,
        private readonly DataTableService $dataTableService,
        private readonly RouteConflictValidator $conflictValidator,
        private readonly AdminPageService $adminPageService,
        private readonly SectionExportImportService $sectionExportImportService,
        private readonly PageFieldService $pageFieldService,
    ) {
    }

    /**
     * Create the CMS app (public and/or admin list+detail pairs).
     *
     * @param array<string, mixed> $input
     * @return array{created: list<array{keyword: string, page_id: int, surface: string, role: string}>}
     */
    public function createCmsApp(array $input): array
    {
        $baseName = strtolower(trim($this->asString($input['base_name'] ?? '')));
        if ($baseName === '' || !preg_match('/^[a-z0-9-]+$/', $baseName)) {
            $this->throwBadRequest('base_name is required and may only contain lowercase letters, numbers and hyphens.');
        }

        $createForm = (bool) ($input['create_form'] ?? false);

        // With create_form the wizard scaffolds a form page that OWNS a fresh
        // data table (named by the new form section id), so no pre-existing
        // table is needed. Without it, bind to an existing table as before.
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

        $recordParam = $this->asString($input['record_id_param'] ?? 'record_id');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $recordParam)) {
            $this->throwBadRequest('record_id_param must be a snake_case identifier.');
        }

        $formFieldName = $this->asString($input['form_field_name'] ?? 'title');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $formFieldName)) {
            $this->throwBadRequest('form_field_name must be a snake_case identifier.');
        }
        $formFieldLabel = $this->asString($input['form_field_label'] ?? '') ?: ucfirst(str_replace('_', ' ', $formFieldName));

        // Multi-field builder: a normalized list of {name, style, label}. Falls
        // back to the single legacy field so existing callers are unaffected.
        $formFields = $this->parseFormFields($input['form_fields'] ?? null, $formFieldName, $formFieldLabel);

        $listTitle = $this->asString($input['list_title'] ?? '') ?: ucfirst($baseName);
        $detailTitle = $this->asString($input['detail_title'] ?? '') ?: ($listTitle . ' detail');
        $accessGroups = $this->intList($input['access_groups'] ?? []);

        // Build the intended page set (keyword, url, surface, role, route). The
        // form page (if any) comes FIRST so its owned table id is known before
        // the list/detail bindings are built.
        $plan = [];
        if ($createForm) {
            $plan[] = $this->formPagePlan($baseName);
        }
        if ($createPublic) {
            $plan[] = $this->pagePlan($baseName, '/' . $baseName, LookupService::PAGE_SURFACE_PUBLIC, 'public_list', false, $recordParam);
            $plan[] = $this->pagePlan($baseName . '-record', '/' . $baseName . '/{' . $recordParam . '}', LookupService::PAGE_SURFACE_PUBLIC, 'public_detail', true, $recordParam);
        }
        if ($createAdmin) {
            $plan[] = $this->pagePlan('cms-' . $baseName, '/cms/' . $baseName, LookupService::PAGE_SURFACE_CMS, 'admin_list', false, $recordParam);
            $plan[] = $this->pagePlan('cms-' . $baseName . '-record', '/cms/' . $baseName . '/{' . $recordParam . '}', LookupService::PAGE_SURFACE_CMS, 'admin_detail', true, $recordParam);
        }

        $this->assertNoConflicts($plan);

        [$contentLocales, $propertyLocale] = $this->resolveLocales();

        $created = [];

        // True atomicity (the plan's "transactional + conflict-checked"): the
        // wizard creates several pages, each with its own route + section import
        // through services that manage their own (now nested) transactions.
        // Enabling savepoints turns those inner begin/commit/rollback into
        // savepoints of this single outer transaction, so ANY failure rolls the
        // whole app creation back — no orphaned pages, routes, or sections. This
        // replaces the previous best-effort page-deletion cleanup, which could
        // itself fail and leave dangling data.
        $connection = $this->entityManager->getConnection();
        $previousSavepoints = $connection->getNestTransactionsWithSavepoints();
        $connection->setNestTransactionsWithSavepoints(true);
        $this->entityManager->beginTransaction();
        // Set when the create-form page is scaffolded (it comes first in the
        // plan); the admin list links its "Add new" button here and the admin
        // detail reuses the form section itself as its edit form.
        $formUrl = null;
        $formSectionId = null;
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
                $created[] = [
                    'keyword' => $entry['keyword'],
                    'page_id' => $pageId,
                    'surface' => $entry['surface'],
                    'role' => $entry['role'],
                ];

                if ($entry['role'] === 'form') {
                    // Scaffold the create/edit form + its owned data table; bind
                    // every later list/detail to that table by its new section id.
                    $formSectionId = $this->scaffoldFormAndTable(
                        $pageId,
                        $baseName,
                        $formFields,
                        $recordParam,
                        $contentLocales,
                        $propertyLocale
                    );
                    $dataTableName = (string) $formSectionId;
                    $formUrl = $entry['url'];
                    // The create form opens as a modal overlay (CMS-in-CMS create);
                    // close_modal_on_save (a form section field) closes it after a
                    // successful submit so the user lands back on the refreshed list.
                    $this->setPageProperty($page, self::FIELD_OPEN_IN_MODAL, '1');
                    continue;
                }

                // The admin record/detail opens as a modal overlay too (view from
                // the list). The public detail stays a normal, shareable full page.
                if ($entry['role'] === 'admin_detail') {
                    $this->setPageProperty($page, self::FIELD_OPEN_IN_MODAL, '1');
                }

                // Admin detail = EDIT FORM (record edit mode): reuse the SAME
                // form-record section as the create page — sections are shared,
                // so both pages write the same data table. Opened with the
                // record route param the form prefills that record and submits
                // an update; opened without (the create page) it stays blank.
                if ($entry['role'] === 'admin_detail' && $formSectionId !== null) {
                    $this->adminPageService->addSectionToPage($pageId, [['sectionId' => $formSectionId]]);
                    continue;
                }

                if ($entry['is_detail']) {
                    $sections = $this->buildDetailSections($baseName, $dataTableName, $recordParam, $detailTitle, $formFields, $contentLocales, $propertyLocale);
                } elseif ($entry['role'] === 'admin_list') {
                    // Admin list = full data table (search / sort / pagination /
                    // delete) bound to the table by numeric id, with an "Add new"
                    // button (the modal form) + a per-row open/view action (the
                    // read-only detail modal).
                    $dataTableId = $this->resolveDataTableId($dataTableName);
                    $sections = $this->buildAdminTableSections($baseName, $dataTableId, $entry['detail_base'], $listTitle, $formUrl, $contentLocales, $propertyLocale);
                } else {
                    // Public list = cards (one per row, with an "Open" link to the
                    // public detail page).
                    $sections = $this->buildListSections($baseName, $dataTableName, $entry['detail_base'], $listTitle, $formFields, $contentLocales, $propertyLocale);
                }

                $this->sectionExportImportService->importSectionsToPage($pageId, $sections);
            }

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e instanceof ServiceException ? $e : new ServiceException(
                'CMS app creation failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()]
            );
        } finally {
            $connection->setNestTransactionsWithSavepoints($previousSavepoints);
        }

        return ['created' => $created];
    }

    /**
     * Plan entry for the optional create form page (`/cms/<base>/form`). It owns
     * a freshly-created data table that the list/detail pages bind to.
     *
     * @return array{keyword: string, url: string, surface: string, role: string, is_detail: bool, path_pattern: string, requirements: array<string, string>|null, detail_base: string}
     */
    private function formPagePlan(string $baseName): array
    {
        $url = '/cms/' . $baseName . '/form';

        return [
            'keyword' => 'cms-' . $baseName . '-form',
            'url' => $url,
            'surface' => LookupService::PAGE_SURFACE_CMS,
            'role' => 'form',
            'is_detail' => false,
            'path_pattern' => $url,
            'requirements' => null,
            'detail_base' => $url,
        ];
    }

    /**
     * Scaffold the create/edit form (`form-record` + one input per field) into
     * the form page, locate the new form section, and materialize its owned
     * data table. Returns the new form section id — it doubles as the data
     * table name the list/detail pages bind to AND as the shared section the
     * admin detail page attaches as its edit form.
     *
     * @param list<array{name: string, style: string, label: string}> $formFields
     * @param list<string> $contentLocales
     */
    private function scaffoldFormAndTable(
        int $pageId,
        string $baseName,
        array $formFields,
        string $recordParam,
        array $contentLocales,
        string $propertyLocale
    ): int {
        $sections = $this->buildFormSections($baseName, $formFields, $recordParam, $contentLocales, $propertyLocale);
        $imported = $this->sectionExportImportService->importSectionsToPage($pageId, $sections);

        $formSectionName = $baseName . '-form';
        $formSectionId = null;
        foreach ($imported as $importedSection) {
            if ($this->asString($importedSection['source_name'] ?? '') === $formSectionName && is_numeric($importedSection['id'] ?? null)) {
                $formSectionId = (int) $importedSection['id'];
                break;
            }
        }
        if ($formSectionId === null) {
            throw new ServiceException('CMS app creation failed: the scaffolded form section could not be located.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $formSection = $this->sectionRepository->find($formSectionId);
        if (!$formSection instanceof Section) {
            throw new ServiceException('CMS app creation failed: the scaffolded form section could not be loaded.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Owned table is named by the form section id; create it now so the
        // list/detail bindings resolve before the first submission.
        $this->dataTableService->createDataTableForFormSection($formSection);

        return $formSectionId;
    }

    /**
     * Set a non-translatable page PROPERTY field (display=0, language 1) on a
     * freshly created page. Used to flip `open_in_modal` on the CMS create/detail
     * pages so the frontend opens them as modal overlays.
     */
    private function setPageProperty(Page $page, string $fieldName, string $value): void
    {
        $field = $this->entityManager->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        if (!$field instanceof Field) {
            throw new ServiceException(
                sprintf('CMS app creation failed: the "%s" page property field is missing — run the pending migrations.', $fieldName),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $this->pageFieldService->updatePageFields($page, [[
            'fieldId' => (int) $field->getId(),
            'languageId' => self::PROPERTY_LANGUAGE_ID,
            'content' => $value,
        ]]);
    }

    /**
     * Resolve the numeric `data_tables.id` for a table name (the entry-table
     * data binding uses the id, not the name).
     */
    private function resolveDataTableId(string $dataTableName): int
    {
        $dataTable = $this->dataService->getDataTableByName($dataTableName);
        if ($dataTable === null) {
            throw new ServiceException(
                sprintf('CMS app creation failed: data table "%s" could not be resolved for the admin list.', $dataTableName),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return (int) $dataTable->getId();
    }

    /**
     * Build the create/edit form: a `form-record` holder in record edit mode
     * with one input per requested field. `load_record_from` binds the record
     * context to the URL: on the create page (no route param) the form stays
     * blank and each submit CREATES a new row; on the admin detail page
     * (`/cms/<base>/{record_id}`) the SAME shared section prefills that record
     * and submits an UPDATE. `own_entries_only=0` is the admin edit-any mode —
     * updating another user's record still requires table UPDATE data access
     * (admins pass via the role override).
     *
     * @param list<array{name: string, style: string, label: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildFormSections(
        string $baseName,
        array $formFields,
        string $recordParam,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $inputChildren = [];
        foreach ($formFields as $field) {
            $inputChildren[] = [
                'section_name' => $baseName . '-form-' . $field['name'],
                'style_name' => $field['style'],
                'fields' => [
                    'name' => $this->propertyField($field['name'], $propertyLocale),
                    'label' => $this->contentField($field['label'], $contentLocales),
                ],
            ];
        }

        return [[
            'section_name' => $baseName . '-form',
            'style_name' => 'form-record',
            'fields' => [
                'name' => $this->propertyField(str_replace('-', '_', $baseName) . '_form', $propertyLocale),
                'alert_success' => $this->contentField('Saved.', $contentLocales),
                // When this form is opened inside a modal (the CMS create/edit
                // flows), a successful save closes it and the list refreshes.
                'close_modal_on_save' => $this->propertyField('1', $propertyLocale),
                // Record edit mode: the record context comes ONLY from the URL.
                'load_record_from' => $this->propertyField($recordParam, $propertyLocale),
                'own_entries_only' => $this->propertyField('0', $propertyLocale),
            ],
            'children' => $inputChildren,
        ]];
    }

    /**
     * @return array{keyword: string, url: string, surface: string, role: string, is_detail: bool, path_pattern: string, requirements: array<string, string>|null, detail_base: string}
     */
    private function pagePlan(string $keyword, string $url, string $surface, string $role, bool $isDetail, string $recordParam): array
    {
        // A list page's `detail_base` is its own url; its generated item links
        // point at `<detail_base>/{{record_id}}`. Detail pages do not use it.
        return [
            'keyword' => $keyword,
            'url' => $url,
            'surface' => $surface,
            'role' => $role,
            'is_detail' => $isDetail,
            'path_pattern' => $url,
            'requirements' => $isDetail ? [$recordParam => '\\d+'] : null,
            'detail_base' => $url,
        ];
    }

    /**
     * Reject the wizard if any intended keyword already exists or the intended
     * active route set conflicts with existing routes or itself.
     *
     * @param list<array{keyword: string, url: string, surface: string, role: string, is_detail: bool, path_pattern: string, requirements: array<string, string>|null, detail_base: string}> $plan
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
     * Resolve the content locales (everything except the internal `all`
     * language) and the property locale (the internal language id 1, used for
     * non-translatable fields like a link URL).
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
            if ((int) $language->getId() === 1) {
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
     * {name, style, label}. When the caller sends no `form_fields`, fall back to
     * the single legacy field (`form_field_name` / `form_field_label`) so older
     * callers keep working unchanged.
     *
     * @param mixed $raw
     * @return list<array{name: string, style: string, label: string}>
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
                $fields[] = ['name' => $name, 'style' => $style, 'label' => $label];
            }
        }

        if ($fields === []) {
            $fields[] = ['name' => $legacyName, 'style' => 'text-input', 'label' => $legacyLabel];
        }

        $names = array_column($fields, 'name');
        if (count($names) !== count(array_unique($names))) {
            $this->throwBadRequest('Form field names must be unique.');
        }

        return $fields;
    }

    /**
     * One editable interpolation line listing every field token (e.g.
     * "{{first_name}} {{last_name}}"). Row columns are top-level tokens in the
     * entry-list/entry-record scope (mirrors the working `{{record_id}}`), so the
     * generated text resolves at render and the editor reshapes it afterwards.
     *
     * @param list<array{name: string, style: string, label: string}> $formFields
     */
    private function fieldInterpolationBlock(array $formFields): string
    {
        return implode(' ', array_map(static fn (array $field): string => '{{' . $field['name'] . '}}', $formFields));
    }

    /**
     * Public list: an `entry-list` of cards (one per row) with an "Open" link to
     * the public detail page. The detail is a normal, shareable full page (NOT a
     * modal), so the public URL stays linkable.
     *
     * @param list<array{name: string, style: string, label: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildListSections(
        string $baseName,
        string $dataTableName,
        string $detailBase,
        string $listTitle,
        array $formFields,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $dataConfig = json_encode([[
            'scope' => 'entries',
            'table' => $dataTableName,
            'retrieve' => 'all',
            'current_user' => false,
        ]]);

        $itemChildren = [
            [
                'section_name' => $baseName . '-list-item-title',
                'style_name' => 'title',
                'fields' => [
                    'content' => $this->contentField($listTitle . ' #{{record_id}}', $contentLocales),
                    'title_order' => $this->propertyField('4', $propertyLocale),
                ],
            ],
            // One editable interpolation line showing every field; reshape after.
            [
                'section_name' => $baseName . '-list-item-fields',
                'style_name' => 'text',
                'fields' => [
                    'text' => $this->contentField($this->fieldInterpolationBlock($formFields), $contentLocales),
                ],
            ],
            [
                'section_name' => $baseName . '-list-item-link',
                'style_name' => 'link',
                'fields' => [
                    'label' => $this->contentField('Open', $contentLocales),
                    'url' => $this->propertyField($detailBase . '/{{record_id}}', $propertyLocale),
                ],
            ],
        ];

        return [[
            'section_name' => $baseName . '-list',
            'style_name' => 'entry-list',
            'global_fields' => ['data_config' => $dataConfig === false ? '' : $dataConfig],
            'children' => [[
                'section_name' => $baseName . '-list-item',
                'style_name' => 'card',
                'children' => $itemChildren,
            ]],
        ]];
    }

    /**
     * Admin list: an `entry-table` DATA TABLE (search / sort / pagination /
     * CSV) bound to the table by numeric id, showing every record. It gets an
     * inline delete control (permission-gated), an "Add new" button that opens
     * the create form modal, and a per-row open/view action that opens the
     * read-only detail modal (`{record_id}` is substituted by the renderer).
     *
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildAdminTableSections(
        string $baseName,
        int $dataTableId,
        string $detailBase,
        string $listTitle,
        ?string $addNewUrl,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $fields = [
            'title' => $this->contentField($listTitle, $contentLocales),
            'data_table' => $this->propertyField((string) $dataTableId, $propertyLocale),
            'own_entries_only' => $this->propertyField('0', $propertyLocale),
            'dt_sortable' => $this->propertyField('1', $propertyLocale),
            'dt_searching' => $this->propertyField('1', $propertyLocale),
            'dt_paginate' => $this->propertyField('1', $propertyLocale),
            'dt_info' => $this->propertyField('1', $propertyLocale),
            'delete_entry' => $this->propertyField('1', $propertyLocale),
            // Single-brace placeholder: substituted client-side per row by the
            // renderer (NOT a {{...}} backend interpolation token).
            'edit_url' => $this->propertyField($detailBase . '/{record_id}', $propertyLocale),
        ];

        if ($addNewUrl !== null) {
            $fields['add_url'] = $this->propertyField($addNewUrl, $propertyLocale);
        }

        return [[
            'section_name' => $baseName . '-admin-table',
            'style_name' => StyleNames::STYLE_ENTRY_TABLE,
            'fields' => $fields,
        ]];
    }

    /**
     * @param list<array{name: string, style: string, label: string}> $formFields
     * @param list<string> $contentLocales
     * @return list<array<string, mixed>>
     */
    private function buildDetailSections(
        string $baseName,
        string $dataTableName,
        string $recordParam,
        string $detailTitle,
        array $formFields,
        array $contentLocales,
        string $propertyLocale
    ): array {
        $dataConfig = json_encode([[
            'scope' => 'record',
            'table' => $dataTableName,
            'retrieve' => 'first',
            'current_user' => false,
            'filter' => 'record_id = {{route.' . $recordParam . '}}',
        ]]);

        return [[
            'section_name' => $baseName . '-detail',
            'style_name' => 'entry-record',
            'global_fields' => ['data_config' => $dataConfig === false ? '' : $dataConfig],
            'children' => [
                [
                    'section_name' => $baseName . '-detail-title',
                    'style_name' => 'title',
                    'fields' => [
                        'content' => $this->contentField($detailTitle, $contentLocales),
                    ],
                ],
                // One editable interpolation line showing every record field.
                [
                    'section_name' => $baseName . '-detail-fields',
                    'style_name' => 'text',
                    'fields' => [
                        'text' => $this->contentField($this->fieldInterpolationBlock($formFields), $contentLocales),
                    ],
                ],
            ],
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
     * Non-translatable (display=0) field value, written under the internal
     * property locale only.
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
