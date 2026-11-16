<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\CMS\DataService;
use App\Service\CMS\FormList\FormListPortability;
use App\Service\Core\LookupService;
use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * A list leaves an install as a page bundle and arrives on another with every
 * reference intact. The ids of the sections change on import; what a list names
 * must therefore not be an id:
 *
 *  - its table is the owner token `@section:<form name>`, relinked to the table of
 *    the form the import creates;
 *  - its columns are the form's input NAMES in the bundle, and the keys
 *    (`section_<id>`) of the new inputs on the importing install.
 *
 * Everything runs through the public admin API, like a real export and import.
 */
#[PhpUnitGroup('form-list')]
final class FormListTemplatePortabilityTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const PREFIX = 'qa_lp2_';
    private const ROUTE_PREFIX = '/qa2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    // -- the round trip ---------------------------------------------------------

    public function testTheColumnsOfAListTravelAsInputNamesAndComeBackAsTheKeysOfTheNewInputs(): void
    {
        $source = $this->sourceApp('qa_lp_src');
        $admin = $this->loginAsQaAdmin();

        $bundle = $this->export([(int) $source['page']->getId()], $admin);

        // The bundle names inputs, never keys of this install.
        $list = $this->bundleSection($bundle, 'entry-form-list');
        foreach (['list_columns', 'list_sort', 'list_controls', 'list_fixed_filter', 'quick_actions'] as $field) {
            $content = $this->bundleField($list, $field);
            self::assertStringNotContainsString('section_', $content, $field . ' carries a key of the source install.');
        }
        self::assertSame(
            '[{"field":"title","label":"Title","sortable":true,"searchable":true},{"field":"seats","format":"number"},{"field":"status"},{"field":"entry_date","format":"date"}]',
            $this->bundleField($list, 'list_columns'),
            'Row fields stay as they are; input keys become input names.',
        );
        self::assertSame('[{"field":"seats","direction":"desc"}]', $this->bundleField($list, 'list_sort'));
        self::assertStringContainsString('"column":"status"', $this->bundleField($list, 'list_fixed_filter'));
        self::assertStringContainsString('"set":{"status":"done"}', $this->bundleField($list, 'quick_actions'));
        self::assertStringContainsString('"column":"status"', $this->bundleField($list, 'quick_actions'), 'The condition of a quick action is rewritten too.');

        // The form inside the list owns the table: its columns and rows travel in the bundle.
        $owner = $this->bundleOwner($bundle, 'qa_lp_src_form');
        self::assertEqualsCanonicalizing(['title', 'seats', 'status'], $owner['columns']);
        self::assertCount(4, $owner['rows']);

        // Import on "another install" (new ids, a prefix on the keywords).
        $imported = $this->import($bundle, $admin, ['importData' => true]);
        $newPageId = $imported[self::PREFIX . 'qa_lp_src'];
        $newListId = $this->firstSectionIdByStyle($newPageId, 'entry-form-list', $admin);
        $newFormId = $this->childFormId($newListId);

        $newKeys = $this->inputKeys($newFormId);
        self::assertSame(['seats', 'status', 'title'], $this->sortedKeys($newKeys));
        self::assertNotSame($source['keys'], $newKeys, 'The new inputs have new ids, so new keys.');

        // The stored fields hold the keys of the NEW inputs, in every place a column is named.
        $columns = $this->storedField($newListId, 'list_columns');
        self::assertStringContainsString('"field":"' . $newKeys['title'] . '"', $columns);
        self::assertStringContainsString('"field":"' . $newKeys['seats'] . '"', $columns);
        self::assertStringContainsString('"field":"entry_date"', $columns);
        foreach ($source['keys'] as $oldKey) {
            foreach (['list_columns', 'list_sort', 'list_controls', 'list_fixed_filter', 'quick_actions'] as $field) {
                self::assertStringNotContainsString($oldKey, $this->storedField($newListId, $field), $field . ' still carries a key of the source install.');
            }
        }
        self::assertSame('[{"field":"' . $newKeys['seats'] . '","direction":"desc"}]', $this->storedField($newListId, 'list_sort'));
        self::assertStringContainsString('"set":{"' . $newKeys['status'] . '":"done"}', $this->storedField($newListId, 'quick_actions'));

        // The imported list renders: the rows come in the saved order, the archived one is filtered out.
        $state = $this->assertEnvelopeSuccess($this->state($this->pageOf($newPageId), $this->sectionOf($newListId), '', $admin));
        $titles = array_map(
            static fn (array $row): mixed => is_array($row['values'] ?? null) ? ($row['values'][$newKeys['title']] ?? null) : null,
            $this->listOf($state, 'rows'),
        );
        self::assertSame(['Beta', 'Gamma', 'Alpha'], $titles);
        self::assertSame(
            [$newKeys['title'], $newKeys['seats'], $newKeys['status'], 'entry_date'],
            array_column($this->listOf($state, 'columns'), 'field'),
        );
    }

    public function testADataViewBoundToTheTableOfAFormTravelsAsATokenAndRelinks(): void
    {
        $source = $this->sourceApp('qa_lp_view');
        $viewPage = $this->viewOf($source);
        $admin = $this->loginAsQaAdmin();

        $bundle = $this->export([(int) $source['page']->getId(), (int) $viewPage->getId()], $admin);

        $exportedView = $this->bundleSection($bundle, 'data-view');
        self::assertSame('@section:qa_lp_view_form', $this->bundleField($exportedView, 'data_table'), 'The table is named by the form that owns it.');
        self::assertStringNotContainsString('section_', $this->bundleField($exportedView, 'list_columns'));

        $imported = $this->import($bundle, $admin, ['importData' => true]);
        $newViewPageId = $imported[self::PREFIX . $viewPage->getKeyword()];
        $newViewId = $this->firstSectionIdByStyle($newViewPageId, 'data-view', $admin);
        $newListId = $this->firstSectionIdByStyle($imported[self::PREFIX . 'qa_lp_view'], 'entry-form-list', $admin);
        $newFormId = $this->childFormId($newListId);

        // The binding is the NEW form's table, and the columns are the keys of the NEW inputs.
        $tableId = $this->storedField($newViewId, 'data_table');
        self::assertMatchesRegularExpression('/^\d+$/', $tableId);
        self::assertSame((string) $newFormId, $this->db->fetchOne('SELECT name FROM data_tables WHERE id = :id', ['id' => $tableId]));
        $newKeys = $this->inputKeys($newFormId);
        self::assertStringContainsString('"field":"' . $newKeys['title'] . '"', $this->storedField($newViewId, 'list_columns'));

        $state = $this->assertEnvelopeSuccess($this->state($this->pageOf($newViewPageId), $this->sectionOf($newViewId), '', $admin));
        self::assertCount(3, $this->listOf($state, 'rows'), 'The view shows the three rows that are not archived.');
    }

    // -- what must be refused ---------------------------------------------------

    public function testABundleWhoseListNamesAColumnTheFormDoesNotHaveIsRefusedAndNothingIsCreated(): void
    {
        $source = $this->sourceApp('qa_lp_bad');
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->export([(int) $source['page']->getId()], $admin);

        $bundle = $this->withBundleField($bundle, 'entry-form-list', 'list_columns', '[{"field":"title"},{"field":"no_such_input"}]');
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => ['keywordPrefix' => self::PREFIX, 'routePrefix' => self::ROUTE_PREFIX],
        ], $admin);

        self::assertSame(422, $envelope['status'] ?? null, (string) json_encode($envelope));
        self::assertStringContainsString('cannot work with its data', (string) json_encode($envelope));
        self::assertStringContainsString('no_such_input', (string) json_encode($envelope));
        self::assertSame('0', self::coerceString($this->db->fetchOne('SELECT COUNT(*) FROM pages WHERE keyword = :k', ['k' => self::PREFIX . 'qa_lp_bad'])), 'A refused import creates nothing.');
    }

    public function testAnExportRefusesAFormWhoseNameAnotherSectionAlsoHas(): void
    {
        $source = $this->sourceApp('qa_lp_dup');
        // A second section with the name of the form: the owner token / owner name would be ambiguous.
        $twin = $this->pages->createSection('qa_lp_dup_form', 'container');
        $this->pages->linkSectionToPage($source['page'], $twin, 20);
        $this->pages->invalidatePageScopedCaches();
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/export', [
            'pageIds' => [(int) $source['page']->getId()],
            'options' => ['includeDataRows' => true],
        ], $admin);

        self::assertSame(400, $envelope['status'] ?? null, (string) json_encode($envelope));
        self::assertStringContainsString('shares its name', (string) json_encode($envelope));
    }

    // -- the link itself --------------------------------------------------------

    public function testRelinkingTwiceDoesTheSameAsRelinkingOnce(): void
    {
        $source = $this->sourceApp('qa_lp_twice');
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->export([(int) $source['page']->getId()], $admin);
        $imported = $this->import($bundle, $admin, ['importData' => true]);
        $newListId = $this->firstSectionIdByStyle($imported[self::PREFIX . 'qa_lp_twice'], 'entry-form-list', $admin);
        $stored = $this->storedField($newListId, 'list_columns');

        $portability = $this->service(FormListPortability::class);
        self::assertFalse($portability->relink($newListId), 'Keys are not names: a second run has nothing to do.');
        self::assertSame($stored, $this->storedField($newListId, 'list_columns'));
    }

    public function testAListOfAnotherInstallsTableIsExportedAsItIs(): void
    {
        // A data view bound to a table that no form of this install owns.
        $tableId = $this->grid->table('qa_lp_elsewhere', ['title' => 'text']);
        [, $view] = $this->dataView('qa_lp_else');
        $this->pages->setSectionField($view, 'data_table', (string) $tableId);
        $this->pages->invalidatePageScopedCaches();

        $fields = ['list_columns' => ['all' => ['content' => '[{"field":"title"}]']]];

        self::assertSame($fields, $this->service(FormListPortability::class)->toPortableFields((int) $view->getId(), $fields));
    }

    // -- the shipped templates --------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> template id => [id, bundle path]
     */
    public static function shippedTemplates(): array
    {
        $cases = [];
        foreach (ExampleBundleTestPaths::cmsInCmsBundles() as $id => $path) {
            $cases[$id] = [$id, $path];
        }

        return $cases;
    }

    /**
     * Every template of the gallery is one dataset with two surfaces: a CMS page
     * whose list holds the form, and a public page that only reads. It must import
     * on fresh ids, render, leave the install as a bundle that names inputs (never
     * keys) and arrive again on another install with every reference relinked.
     */
    #[DataProvider('shippedTemplates')]
    public function testAShippedTemplateImportsOnFreshIdsAndSurvivesAnExportAndReimport(string $id, string $bundlePath): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->decodedBundle($bundlePath);

        // The shape of the redesigned gallery: one list that holds the one form.
        $roles = [];
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
            if (is_array($page) && is_string($page['cms_app_role'] ?? null)) {
                $roles[] = $page['cms_app_role'];
            }
        }
        self::assertContains('cms_list', $roles, $id);
        self::assertContains('public_list', $roles, $id);
        self::assertNotContains('form', $roles, $id . ' keeps no second page for the form.');
        self::assertNotContains('cms_detail', $roles, $id . ' edits records inside the list.');
        self::assertSame(1, $this->countStyle($bundle, 'entry-form-list'), $id . ' has exactly one participant list.');
        self::assertSame(1, $this->countStyle($bundle, 'form-record'), $id . ' defines its inputs once.');
        self::assertSame(0, $this->countStyle($bundle, 'entry-table'), $id . ' no longer uses a table that edits through a second form.');
        $tables = is_array($bundle['data_tables'] ?? null) ? array_values($bundle['data_tables']) : [];
        self::assertCount(1, $tables, $id . ' ships one dataset.');
        $sampleRows = count(self::asList(self::asArray($tables[0])['rows'] ?? []));
        self::assertGreaterThan(1, $sampleRows, $id . ' ships sample records.');

        $first = $this->importAs($bundle, $admin, 'qa_lp3a_', '/qa3a');
        $firstList = $this->firstSectionIdByStyle($first['cms_list'], 'entry-form-list', $admin);
        $firstForm = $this->childFormId($firstList);
        $firstKeys = $this->inputKeys($firstForm);
        self::assertNotSame([], $firstKeys, $id . ' ships inputs.');
        $firstState = $this->assertEnvelopeSuccess($this->state($this->pageOf($first['cms_list']), $this->sectionOf($firstList), '', $admin));
        self::assertSame($sampleRows, $this->listTotal($firstState), $id . ' shows every sample record to its manager.');

        // The sample records arrive through the import door: every one has a first version, written by the import.
        $doors = $this->db->fetchFirstColumn(
            'SELECT DISTINCT src.lookup_code FROM data_revisions r'
            . ' JOIN lookups src ON src.id = r.id_sources JOIN data_rows d ON d.id = r.id_data_rows'
            . ' WHERE d.id_data_tables = :t',
            ['t' => $this->dataTableIdOf($firstForm)],
        );
        self::assertSame(['import'], $doors, $id . ': imported records name the import as the door of their history.');

        // Out and in again, on "another install".
        $exported = $this->export(array_values($first), $admin);
        foreach ($this->entryListConfigs($exported) as $field => $content) {
            self::assertStringNotContainsString('section_', $content, $id . ': ' . $field . ' of the export names an input key.');
        }
        $second = $this->importAs($exported, $admin, 'qa_lp3b_', '/qa3b');
        $secondList = $this->firstSectionIdByStyle($second['cms_list'], 'entry-form-list', $admin);
        $secondForm = $this->childFormId($secondList);
        $secondKeys = $this->inputKeys($secondForm);

        self::assertNotSame($firstList, $secondList);
        self::assertNotSame($firstForm, $secondForm);
        self::assertNotSame($firstKeys, $secondKeys, 'The inputs of the second install have ids of their own.');
        self::assertSame($this->sortedKeys($firstKeys), $this->sortedKeys($secondKeys));
        self::assertNotSame($this->dataTableIdOf($firstForm), $this->dataTableIdOf($secondForm), 'Each install has a table of its own.');

        // Nothing of the first install is left in the configuration or in a link of the second.
        $secondText = $this->pageConfiguration(array_values($second));
        foreach ($firstKeys as $oldKey) {
            self::assertStringNotContainsString('"' . $oldKey . '"', $secondText, $id . ' still names an input of the first install.');
        }
        // An export carries the routes it had, so the prefix of the second import stacks on them: every link
        // that names the first install's routes must sit behind the prefix of the second one.
        $leaks = preg_match('#(?<!/qa3b)/qa3a#', $secondText, $found, PREG_OFFSET_CAPTURE);
        self::assertSame(0, $leaks, $id . ' still links into the first install: ' . ($leaks === 1 ? substr($secondText, max(0, (int) $found[0][1] - 80), 200) : ''));
        if (in_array('public_detail', $roles, true)) {
            self::assertStringContainsString('/qa3b', $secondText, $id . ' links with the prefix of the install it arrived on.');
        }

        // The same records on both, and the same labels.
        $secondState = $this->assertEnvelopeSuccess($this->state($this->pageOf($second['cms_list']), $this->sectionOf($secondList), '', $admin));
        self::assertSame($sampleRows, $this->listTotal($secondState));
        self::assertSame(
            array_column($this->listOf($firstState, 'columns'), 'label'),
            array_column($this->listOf($secondState, 'columns'), 'label'),
        );
        self::assertSame($this->firstColumnTexts($firstState), $this->firstColumnTexts($secondState), $id . ' lists the same records on the second install.');

        // The public page of the second install shows what the first one shows.
        $shownFirst = $this->publicMarkers($first['public_list'], $admin, $firstState);
        $shownSecond = $this->publicMarkers($second['public_list'], $admin, $secondState);
        self::assertNotSame([], $shownSecond, $id . ' shows records on its public page.');
        self::assertSame($shownFirst, $shownSecond, $id . ' shows the same records on its public page after the round trip.');

        // A data view that draws its records from a template keeps the template through the round trip: the
        // sections inside the view are travelling with it, are new sections of the second install and draw
        // the same records there.
        if ($this->countStyle($bundle, 'data-view') === 1) {
            $exportedView = $this->bundleSection($exported, 'data-view');
            self::assertSame('custom', $this->bundleField($exportedView, 'list_layout'), $id . ': the export keeps the layout of the view.');
            self::assertNotSame([], $exportedView['children'] ?? [], $id . ': the export carries the record template.');

            $firstViewId = $this->firstSectionIdByStyle($first['public_list'], 'data-view', $admin);
            $secondViewId = $this->firstSectionIdByStyle($second['public_list'], 'data-view', $admin);
            self::assertNotSame($firstViewId, $secondViewId);
            $firstView = $this->assertEnvelopeSuccess($this->state($this->pageOf($first['public_list']), $this->sectionOf($firstViewId), '', $admin));
            $secondView = $this->assertEnvelopeSuccess($this->state($this->pageOf($second['public_list']), $this->sectionOf($secondViewId), '', $admin));
            self::assertTrue($this->at($secondView, 'list')['has_template'] ?? false, $id . ': the view on the second install has its record template.');
            $firstRows = $this->listOf($firstView, 'rows');
            $secondRows = $this->listOf($secondView, 'rows');
            self::assertNotSame([], $secondRows);
            self::assertCount(count($firstRows), $secondRows);
            foreach ($secondRows as $i => $row) {
                self::assertNotSame([], $row['sections'] ?? [], $id . ': a record of the second install is drawn from the template.');
                $card = $this->cardTexts($row['sections'] ?? null);
                self::assertNotSame([], $card);
                self::assertSame(
                    $this->cardTexts($firstRows[$i]['sections'] ?? null),
                    $card,
                    $id . ': both installs draw the same card for the same record.',
                );
            }
        }
    }

    // -- fixtures ---------------------------------------------------------------

    /**
     * A private page with a participant list, the form inside it (inputs title,
     * seats, status) and four saved records, one of them archived. The list names
     * its columns by input key in every place that can.
     *
     * @return array{page: Page, list: Section, form: Section, keys: array<string, string>}
     */
    private function sourceApp(string $prefix): array
    {
        $page = $this->pages->createPage($prefix, false);
        $list = $this->pages->createSection($prefix . '_list', 'entry-form-list');
        $form = $this->pages->createSection($prefix . '_form', 'form-record');
        $this->pages->linkSectionToPage($page, $list, 10);
        $this->pages->linkSectionToParent($list, $form, 10);
        $inputs = [
            'title' => $this->formInput($form, $prefix . '_title', 'text-input', 'title', 10),
            'seats' => $this->formInput($form, $prefix . '_seats', 'number-input', 'seats', 20),
            'status' => $this->formInput($form, $prefix . '_status', 'text-input', 'status', 30),
        ];
        $keys = array_map(static fn (Section $input): string => 'section_' . $input->getId(), $inputs);

        $fields = [
            'own_entries_only' => '0',
            'list_columns' => json_encode([
                ['field' => $keys['title'], 'label' => 'Title', 'sortable' => true, 'searchable' => true],
                ['field' => $keys['seats'], 'format' => 'number'],
                ['field' => $keys['status']],
                ['field' => 'entry_date', 'format' => 'date'],
            ], JSON_THROW_ON_ERROR),
            'list_sort' => json_encode([['field' => $keys['seats'], 'direction' => 'desc']], JSON_THROW_ON_ERROR),
            'list_controls' => json_encode([
                ['id' => 'state', 'type' => 'select', 'field' => $keys['status'], 'label' => 'Status', 'options_from' => 'data'],
                ['id' => 'seats', 'type' => 'number_range', 'field' => $keys['seats'], 'label' => 'Seats'],
            ], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => json_encode(['mode' => 'and', 'children' => [['column' => $keys['status'], 'operator' => 'neq', 'value' => 'archived']]], JSON_THROW_ON_ERROR),
            'quick_actions' => json_encode([[
                'id' => 'done',
                'label' => 'Done',
                'set' => [$keys['status'] => 'done'],
                'when' => ['mode' => 'and', 'children' => [['column' => $keys['status'], 'operator' => 'eq', 'value' => 'open']]],
            ]], JSON_THROW_ON_ERROR),
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($list, $name, $content);
        }

        $data = $this->service(DataService::class);
        foreach ([['Alpha', '5', 'open'], ['Beta', '15', 'done'], ['Gamma', '10', 'open'], ['Delta', '20', 'archived']] as [$title, $seats, $status]) {
            $data->saveData((string) $form->getId(), ['title' => $title, 'seats' => $seats, 'status' => $status], LookupService::TRANSACTION_BY_BY_USER, null, false);
        }
        $this->pages->invalidatePageScopedCaches();

        return ['page' => $page, 'list' => $list, 'form' => $form, 'keys' => $keys];
    }

    /**
     * A public data view over the table of the form of a source app.
     *
     * @param array{page: Page, list: Section, form: Section, keys: array<string, string>} $source
     */
    private function viewOf(array $source): Page
    {
        $prefix = $source['page']->getKeyword() . '_page';
        $tableId = self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => (string) $source['form']->getId()]));
        self::assertGreaterThan(0, $tableId, 'The saved records created the table of the form.');

        $page = $this->pages->createPage($prefix, true);
        $view = $this->pages->createSection($prefix . '_view', 'data-view');
        $this->pages->linkSectionToPage($page, $view, 10);
        $keys = $source['keys'];
        $fields = [
            'data_table' => (string) $tableId,
            'list_columns' => json_encode([['field' => $keys['title'], 'label' => 'Title'], ['field' => $keys['seats'], 'format' => 'number']], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => json_encode(['mode' => 'and', 'children' => [['column' => $keys['status'], 'operator' => 'neq', 'value' => 'archived']]], JSON_THROW_ON_ERROR),
        ];
        foreach ($fields as $name => $content) {
            $this->pages->setSectionField($view, $name, $content);
        }
        $this->pages->invalidatePageScopedCaches();

        return $page;
    }

    // -- calls ------------------------------------------------------------------

    /**
     * @param list<int> $pageIds
     * @return array<string, mixed>
     */
    private function export(array $pageIds, string $token): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/pages/export', [
            'pageIds' => $pageIds,
            'options' => ['includeDataRows' => true],
        ], $token));
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     * @return array<string, int> imported page id by keyword
     */
    private function import(array $bundle, string $token, array $options = []): array
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => ['keywordPrefix' => self::PREFIX, 'routePrefix' => self::ROUTE_PREFIX] + $options,
        ], $token);
        self::assertSame(201, $envelope['status'] ?? null, 'The import is accepted: ' . json_encode($envelope));
        $data = $this->assertEnvelopeSuccess($envelope, 201);

        $ids = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['keyword'] ?? null) && is_int($entry['page_id'] ?? null)) {
                $ids[$entry['keyword']] = $entry['page_id'];
            }
        }
        self::assertNotSame([], $ids, 'The import created pages.');

        return $ids;
    }

    private function firstSectionIdByStyle(int $pageId, string $style, string $token): int
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token));
        $id = $this->findSectionId(is_array($data['sections'] ?? null) ? $data['sections'] : [], $style);
        self::assertNotNull($id, sprintf('Page %d must carry a "%s" section.', $pageId, $style));

        return $id;
    }

    /**
     * @param array<int|string, mixed> $sections
     */
    private function findSectionId(array $sections, string $style): ?int
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $style && is_int($section['id'] ?? null)) {
                return $section['id'];
            }
            if (is_array($section['children'] ?? null) && ($found = $this->findSectionId($section['children'], $style)) !== null) {
                return $found;
            }
        }

        return null;
    }

    // -- reading the result -----------------------------------------------------

    private function pageOf(int $pageId): Page
    {
        $page = $this->em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Page::class, $page);

        return $page;
    }

    private function sectionOf(int $sectionId): Section
    {
        $section = $this->em->getRepository(Section::class)->find($sectionId);
        self::assertInstanceOf(Section::class, $section);

        return $section;
    }

    private function storedField(int $sectionId, string $field): string
    {
        $content = $this->db->fetchOne(
            'SELECT t.content FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections = :s AND f.name = :f AND t.id_languages = 1',
            ['s' => $sectionId, 'f' => $field],
        );
        self::assertIsString($content, sprintf('Section %d stores "%s".', $sectionId, $field));

        return $content;
    }

    private function childFormId(int $listId): int
    {
        return self::coerceInt($this->db->fetchOne(
            'SELECT h.id_child_section FROM rel_sections_hierarchy h JOIN sections s ON s.id = h.id_child_section'
            . ' JOIN styles st ON st.id = s.id_styles WHERE h.id_parent_section = :l AND st.name = :style',
            ['l' => $listId, 'style' => 'form-record'],
        ));
    }

    /**
     * Input name => key (`section_<id>`) of the inputs of a form.
     *
     * @return array<string, string>
     */
    private function inputKeys(int $formId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT h.id_child_section AS id, t.content AS name FROM rel_sections_hierarchy h'
            . ' JOIN sections_fields_translation t ON t.id_sections = h.id_child_section'
            . ' JOIN fields f ON f.id = t.id_fields AND f.name = :field'
            . ' WHERE h.id_parent_section = :form',
            ['field' => 'name', 'form' => $formId],
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[self::coerceString($row['name'])] = 'section_' . self::coerceInt($row['id']);
        }

        return $keys;
    }

    /**
     * @param array<string, string> $keys
     * @return list<string>
     */
    private function sortedKeys(array $keys): array
    {
        $names = array_keys($keys);
        sort($names);

        return $names;
    }

    // -- reading the templates --------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function decodedBundle(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return self::asArray($decoded);
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function countStyle(array $bundle, string $style): int
    {
        $count = 0;
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
            $count += $this->countStyleIn(is_array($page) ? ($page['sections'] ?? null) : null, $style);
        }

        return $count;
    }

    private function countStyleIn(mixed $sections, string $style): int
    {
        $count = 0;
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $count += ($section['style_name'] ?? null) === $style ? 1 : 0;
            $count += $this->countStyleIn($section['children'] ?? null, $style);
        }

        return $count;
    }

    /**
     * Import a bundle under a prefix (new ids) with its sample data, and name the
     * created pages by their app role.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, int> page id by cms_app_role
     */
    private function importAs(array $bundle, string $token, string $keywordPrefix, string $routePrefix): array
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => ['keywordPrefix' => $keywordPrefix, 'routePrefix' => $routePrefix, 'importData' => true],
        ], $token);
        self::assertSame(201, $envelope['status'] ?? null, 'The import is accepted: ' . json_encode($envelope));
        $data = $this->assertEnvelopeSuccess($envelope, 201);

        $roleByKeyword = [];
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
            if (is_array($page) && is_string($page['keyword'] ?? null) && is_string($page['cms_app_role'] ?? null)) {
                $roleByKeyword[$keywordPrefix . $page['keyword']] = $page['cms_app_role'];
            }
        }
        $byRole = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['keyword'] ?? null) && is_int($entry['page_id'] ?? null) && isset($roleByKeyword[$entry['keyword']])) {
                $byRole[$roleByKeyword[$entry['keyword']]] = $entry['page_id'];
            }
        }
        self::assertArrayHasKey('cms_list', $byRole, 'The import created the CMS page: ' . json_encode($data));
        self::assertArrayHasKey('public_list', $byRole, 'The import created the public page: ' . json_encode($data));

        return $byRole;
    }

    /**
     * The configuration texts of every list or data view of a bundle, by section and field.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, string>
     */
    private function entryListConfigs(array $bundle): array
    {
        $found = [];
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
            $this->collectListConfigs(is_array($page) ? ($page['sections'] ?? null) : null, $found);
        }

        return $found;
    }

    /**
     * @param array<string, string> $found
     */
    private function collectListConfigs(mixed $sections, array &$found): void
    {
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (in_array($section['style_name'] ?? null, ['entry-form-list', 'data-view'], true)) {
                foreach (['list_columns', 'list_sort', 'list_controls', 'list_fixed_filter', 'quick_actions'] as $field) {
                    $entries = is_array($section['fields'] ?? null) && is_array($section['fields'][$field] ?? null) ? $section['fields'][$field] : [];
                    foreach ($entries as $entry) {
                        if (is_array($entry) && is_string($entry['content'] ?? null)) {
                            $found[self::coerceString($section['section_name'] ?? '') . ':' . $field] = $entry['content'];
                        }
                    }
                }
            }
            $this->collectListConfigs($section['children'] ?? null, $found);
        }
    }

    /**
     * Everything the sections of some pages are configured with, as one text.
     *
     * @param list<int> $pageIds
     */
    private function pageConfiguration(array $pageIds): string
    {
        $ids = implode(',', array_map('intval', $pageIds));
        $contents = $this->db->fetchFirstColumn(
            'WITH RECURSIVE tree (id) AS ('
            . ' SELECT ps.id_sections FROM rel_pages_sections ps WHERE ps.id_pages IN (' . $ids . ')'
            . ' UNION ALL'
            . ' SELECT h.id_child_section FROM rel_sections_hierarchy h JOIN tree ON tree.id = h.id_parent_section'
            . ') SELECT t.content FROM sections_fields_translation t JOIN tree ON tree.id = t.id_sections',
        );

        return implode("\n", array_map(static fn (mixed $content): string => is_string($content) ? $content : '', $contents));
    }

    private function dataTableIdOf(int $formId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => (string) $formId]));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function listTotal(array $state): int
    {
        return self::coerceInt(self::asArray($state['pagination'] ?? [])['total'] ?? 0);
    }

    /**
     * The text of the first column over the rows of a list state, sorted: the
     * records, whatever the order the ties of the sort fell in.
     *
     * @param array<string, mixed> $state
     * @return list<string>
     */
    private function firstColumnTexts(array $state): array
    {
        $columns = $this->listOf($state, 'columns');
        $field = self::coerceString($columns[0]['field'] ?? '');
        $texts = [];
        foreach ($this->listOf($state, 'rows') as $row) {
            $texts[] = self::coerceString(self::asArray($row['values'] ?? [])[$field] ?? '');
        }
        sort($texts);

        return $texts;
    }

    /**
     * The texts a card shows, sorted: the content of every field of every section in it. Ids, names, the
     * order the fields are stored in, the id of the record in its link and the route prefixes of the two
     * imports are the install's own; the texts are the card.
     *
     * @return list<string>
     */
    private function cardTexts(mixed $node): array
    {
        $texts = array_map(
            static fn (string $text): string => (string) preg_replace(['#(/qa3[ab])+#', '#/\d+$#'], ['', '/N'], $text),
            $this->collectTexts($node),
        );
        sort($texts);

        return $texts;
    }

    /**
     * @return list<string>
     */
    private function collectTexts(mixed $node): array
    {
        $texts = [];
        if (!is_array($node)) {
            return $texts;
        }
        foreach ($node as $key => $value) {
            if ($key === 'content' && is_string($value)) {
                $texts[] = $value;
                continue;
            }
            array_push($texts, ...$this->collectTexts($value));
        }

        return $texts;
    }

    /**
     * The texts of the records of a list that the public page shows, sorted.
     *
     * @param array<string, mixed> $state the state of the list the managers see
     * @return list<string>
     */
    private function publicMarkers(int $publicPageId, string $token, array $state): array
    {
        $path = self::coerceString($this->db->fetchOne('SELECT url FROM pages WHERE id = :id', ['id' => $publicPageId]));
        $this->client->request('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode($path) . '&preview=true', [], [], $this->authHeaders($token));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getContent());
        $decoded = json_decode((string) $response->getContent(), true);
        $haystack = is_array($decoded) ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $shown = [];
        foreach ($this->listOf($state, 'rows') as $row) {
            foreach (self::asArray($row['values'] ?? []) as $value) {
                $text = is_string($value) ? trim($value) : '';
                if (mb_strlen($text) >= 4 && str_contains($haystack, $text)) {
                    $shown[$text] = true;
                }
            }
        }
        $texts = array_map('strval', array_keys($shown));
        sort($texts);

        return $texts;
    }

    // -- reading the bundle -----------------------------------------------------

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function bundleSection(array $bundle, string $style): array
    {
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
            $found = is_array($page) ? $this->findInTree($page['sections'] ?? null, $style) : null;
            if ($found !== null) {
                return $found;
            }
        }
        self::fail(sprintf('The bundle carries no "%s" section.', $style));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInTree(mixed $sections, string $style): ?array
    {
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $style) {
                $result = [];
                foreach ($section as $key => $value) {
                    $result[(string) $key] = $value;
                }

                return $result;
            }
            $found = $this->findInTree($section['children'] ?? null, $style);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function bundleField(array $section, string $field): string
    {
        $entries = is_array($section['fields'] ?? null) && is_array($section['fields'][$field] ?? null) ? $section['fields'][$field] : [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['content'] ?? null)) {
                return $entry['content'];
            }
        }
        self::fail(sprintf('The bundle section carries no "%s".', $field));
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array{columns: list<string>, rows: list<mixed>}
     */
    private function bundleOwner(array $bundle, string $ownerName): array
    {
        foreach (is_array($bundle['data_tables'] ?? null) ? $bundle['data_tables'] : [] as $table) {
            $table = self::asArray($table);
            if (($table['owner_section_name'] ?? null) !== $ownerName) {
                continue;
            }
            $columns = [];
            foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
                $columns[] = self::coerceString($column);
            }
            $rows = [];
            foreach (is_array($table['rows'] ?? null) ? $table['rows'] : [] as $row) {
                $rows[] = $row;
            }

            return ['columns' => $columns, 'rows' => $rows];
        }
        self::fail(sprintf('The bundle carries no data table of "%s".', $ownerName));
    }

    /**
     * The bundle with one field of the first section of a style replaced.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function withBundleField(array $bundle, string $style, string $field, string $content): array
    {
        $pages = is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [];
        foreach ($pages as $index => $page) {
            $page = self::asArray($page);
            $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
            $replaced = $this->replaceInSections($sections, $style, $field, $content);
            if ($replaced !== null) {
                $page['sections'] = $replaced;
                $pages[$index] = $page;
                $bundle['pages'] = $pages;

                return $bundle;
            }
        }
        self::fail(sprintf('The bundle carries no "%s" section.', $style));
    }

    /**
     * @param array<mixed> $sections
     * @return array<mixed>|null The sections with the field replaced, or null when no section has the style.
     */
    private function replaceInSections(array $sections, string $style, string $field, string $content): ?array
    {
        foreach ($sections as $index => $section) {
            $section = self::asArray($section);
            if (($section['style_name'] ?? null) === $style) {
                $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
                $fields[$field] = ['all' => ['content' => $content]];
                $section['fields'] = $fields;
                $sections[$index] = $section;

                return $sections;
            }
            $children = is_array($section['children'] ?? null) ? $section['children'] : [];
            $replaced = $this->replaceInSections($children, $style, $field, $content);
            if ($replaced !== null) {
                $section['children'] = $replaced;
                $sections[$index] = $section;

                return $sections;
            }
        }

        return null;
    }
}
