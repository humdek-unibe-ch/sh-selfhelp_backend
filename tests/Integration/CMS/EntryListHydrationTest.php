<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\Field;
use App\Entity\Group;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsFieldsTranslation;
use App\Entity\SectionsHierarchy;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The CMS-in-CMS "N cards" contract (issue #30): an `entry-list` bound to a
 * data table via property fields (`data_table`, `own_entries_only`, …) clones
 * flattens each row's cells into the clone's interpolation data, so `{{col}}`
 * resolves per card. `data_config` may still supply helper scopes (e.g.
 * `filters`) for the author `filter` field but must never choose the row
 * table. This is the exact mechanism the template gallery's public
 * list pages rely on; rows are created through the real form-submit endpoint
 * (never raw SQL) so the table/columns/cells match production.
 */
final class EntryListHydrationTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var EntityManagerInterface $em */
        $em = $this->service(EntityManagerInterface::class);
        $this->em = $em;
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testEntryListClonesChildTemplateOncePerDataRow(): void
    {
        // 1. A real form page creates the owned data table through the
        //    form-submit endpoint (three rows = three expected cards).
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_rows', openAccess: true);

        $values = ['Ada', 'Grace', 'Alan'];
        $recordIds = [];
        foreach ($values as $value) {
            $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $formPage->getId(),
                'section_id' => (int) $formSection->getId(),
                'form_data' => ['qa_answer' => $value],
            ]);
            $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
            self::assertIsInt($recordId);
            $recordIds[] = $recordId;
        }

        $firstRow = $this->em->getRepository(DataRow::class)->find($recordIds[0]);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        // 2. A list page with an entry-list bound to that table and a child
        //    template interpolating the submitted column.
        $listPage = $this->pages->createPage('qa_el_list', openAccess: false);
        $this->pages->grantGroupAcl(
            $listPage,
            $this->subjectGroup(),
            select: true,
            insert: false,
            update: false,
            delete: false,
            affectedUserIds: [],
        );

        $listSection = $this->pages->createSection('qa_el_list_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->em->flush();

        $cardSection = $this->pages->createSection('qa_el_list_card', 'text');
        $this->linkChild($listSection, $cardSection);
        // `text` is a content field; cover both seeded content languages.
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 3);

        $this->pages->invalidatePageScopedCaches();

        // 3. Render: exactly one clone per row, each with its own value.
        $token = $this->loginAsQaUser();
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true',
            null,
            $token
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        $sections = $this->sectionsFromPayload($data);
        $entryList = $this->findSectionByStyle($sections, 'entry-list');
        self::assertNotNull($entryList, 'The rendered page must contain the entry-list section.');

        $children = is_array($entryList['children'] ?? null) ? $entryList['children'] : [];
        self::assertCount(
            count($values),
            $children,
            'The child template must be cloned once per data row (N cards).'
        );

        $texts = [];
        foreach ($children as $child) {
            self::assertIsArray($child);
            $textField = is_array($child['text'] ?? null) ? $child['text'] : [];
            $texts[] = $textField['content'] ?? null;
        }
        sort($texts);
        $expected = $values;
        sort($expected);
        self::assertSame(
            $expected,
            $texts,
            'Each clone must interpolate {{qa_answer}} with its own row value.'
        );
    }

    public function testEntryListInjectsUnderscoredLabelsForEveryOptionStyleFamily(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_select_rows', openAccess: true);
        $optionSections = [
            [
                'section' => $this->pages->createSection('qa_el_select_category', 'select'),
                'name' => 'category',
                'catalog_field' => 'options',
                'multiple_field' => null,
            ],
            [
                'section' => $this->pages->createSection('qa_el_select_tags', 'select'),
                'name' => 'tags',
                'catalog_field' => 'options',
                'multiple_field' => 'is_multiple',
            ],
            [
                'section' => $this->pages->createSection('qa_el_radio_channel', 'radio'),
                'name' => 'channel',
                'catalog_field' => 'radio_options',
                'multiple_field' => null,
            ],
            [
                'section' => $this->pages->createSection('qa_el_combobox_topics', 'combobox'),
                'name' => 'topics',
                'catalog_field' => 'combobox_options',
                'multiple_field' => 'web_combobox_multi_select',
            ],
            [
                'section' => $this->pages->createSection('qa_el_segment_view', 'segmented-control'),
                'name' => 'view',
                'catalog_field' => 'segmented_control_data',
                'multiple_field' => null,
            ],
        ];
        foreach ($optionSections as $optionSection) {
            $section = $optionSection['section'];
            $this->linkChild($formSection, $section);
            $this->setSectionField($section, 'name', $optionSection['name'], 1);
            $this->setSectionField(
                $section,
                $optionSection['catalog_field'],
                '[{"value":"release","sort":1},{"value":"feature","sort":2},{"value":"notice","sort":3}]',
                1
            );
            $this->setSectionField(
                $section,
                'option_labels',
                '{"release":"Freigabe","feature":"Funktion","notice":"Hinweis"}',
                2
            );
            $this->setSectionField(
                $section,
                'option_labels',
                '{"release":"Release","feature":"Feature","notice":"Notice"}',
                3
            );
            if (is_string($optionSection['multiple_field'])) {
                $this->setSectionField($section, $optionSection['multiple_field'], '1', 1);
            }
        }

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => [
                'qa_answer' => 'row-1',
                'category' => 'release',
                'tags' => 'release,notice',
                'channel' => 'notice',
                'topics' => 'feature,notice',
                'view' => 'feature',
            ],
        ]);
        $recordId = $this->assertEnvelopeSuccess($envelope)['record_id'] ?? null;
        self::assertIsInt($recordId);

        $row = $this->em->getRepository(DataRow::class)->find($recordId);
        self::assertInstanceOf(DataRow::class, $row);
        $tableName = (string) $row->getDataTable()?->getName();

        $listPage = $this->pages->createPage('qa_el_select_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false, affectedUserIds: []);

        $listSection = $this->pages->createSection('qa_el_select_list_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $dataTable = $row->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->em->flush();

        $cardSection = $this->pages->createSection('qa_el_select_list_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $labelTemplate = '{{_category_label}}|{{_tags_labels}}|{{_channel_label}}|{{_topics_labels}}|{{_view_label}}';
        $this->setSectionField($cardSection, 'text', $labelTemplate, 2);
        $this->setSectionField($cardSection, 'text', $labelTemplate, 3);

        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();
        $dataDe = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true&language_id=2', null, $token)
        );
        $dataEn = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true&language_id=3', null, $token)
        );
        $sectionsDe = is_array($dataDe['page'] ?? null) && is_array($dataDe['page']['sections'] ?? null)
            ? $dataDe['page']['sections']
            : (is_array($dataDe['sections'] ?? null) ? $dataDe['sections'] : []);
        $sectionsEn = is_array($dataEn['page'] ?? null) && is_array($dataEn['page']['sections'] ?? null)
            ? $dataEn['page']['sections']
            : (is_array($dataEn['sections'] ?? null) ? $dataEn['sections'] : []);
        $entryListDe = $this->findSectionByStyle($sectionsDe, 'entry-list');
        $entryListEn = $this->findSectionByStyle($sectionsEn, 'entry-list');
        self::assertNotNull($entryListDe);
        self::assertNotNull($entryListEn);
        self::assertSame(
            'Freigabe|Freigabe, Hinweis|Hinweis|Funktion, Hinweis|Funktion',
            $this->firstChildTextContent($entryListDe)
        );
        self::assertSame(
            'Release|Release, Notice|Notice|Feature, Notice|Feature',
            $this->firstChildTextContent($entryListEn)
        );
    }

    public function testEntryListScopePrefixExposesScopedInterpolationTokens(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_scope', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'Scoped'],
        ]);
        $this->assertEnvelopeSuccess($envelope);

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $listPage = $this->pages->createPage('qa_el_scope_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_scope_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->setSectionField($listSection, 'scope', 'item', 1);

        $cardSection = $this->pages->createSection('qa_el_scope_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{item.qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $sections = $this->sectionsFromPayload($data);
        $entryList = $this->findSectionByStyle($sections, 'entry-list');
        self::assertNotNull($entryList);
        self::assertSame('Scoped', $this->firstChildTextContent($entryList));
    }

    public function testEntryListOwnEntriesOnlyDefaultShowsOnlyCurrentUserRows(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_own', openAccess: false);
        $this->pages->grantGroupAcl(
            $formPage,
            $this->subjectGroup(),
            select: true,
            insert: true,
            update: false,
            delete: false,
        );

        $ownerEnvelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'owner-row'],
        ], $this->loginAsQaUser());
        $this->assertEnvelopeSuccess($ownerEnvelope);

        $otherEnvelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'other-row'],
        ], $this->loginAsQaAdmin());
        $this->assertEnvelopeSuccess($otherEnvelope);

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);

        $listPage = $this->pages->createPage('qa_el_own_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_own_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        // own_entries_only left unset — catalog default is checked (own rows only).

        $cardSection = $this->pages->createSection('qa_el_own_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $sections = $this->sectionsFromPayload($data);
        $entryList = $this->findSectionByStyle($sections, 'entry-list');
        self::assertNotNull($entryList);
        $children = is_array($entryList['children'] ?? null) ? $entryList['children'] : [];
        self::assertCount(1, $children, 'Default own_entries_only must hide other users rows.');
        self::assertSame('owner-row', $this->firstChildTextContent($entryList));
    }

    public function testEntryListSelectedColumnsLimitsLoadedFields(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_cols', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => ['qa_answer' => 'only-col'],
        ]);
        $this->assertEnvelopeSuccess($envelope);

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        $columnsEnvelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/data/tables/' . rawurlencode($tableName) . '/columns',
            null,
            $this->loginAsQaAdmin(),
        );
        $columnsData = $this->assertEnvelopeSuccess($columnsEnvelope);
        $columns = is_array($columnsData['columns'] ?? null) ? $columnsData['columns'] : [];
        self::assertNotEmpty($columns);
        $fieldKey = '';
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $candidate = $column['fieldKey'] ?? '';
            if (is_string($candidate) && $candidate !== '' && !($column['standard'] ?? false)) {
                $fieldKey = $candidate;
                break;
            }
        }
        self::assertNotSame('', $fieldKey, 'Form-owned table must expose a non-standard field key.');

        $listPage = $this->pages->createPage('qa_el_cols_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_cols_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->setSectionField($listSection, 'selected_columns', $fieldKey, 1);

        $cardSection = $this->pages->createSection('qa_el_cols_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $sections = $this->sectionsFromPayload($data);
        $entryList = $this->findSectionByStyle($sections, 'entry-list');
        self::assertNotNull($entryList);
        self::assertSame('only-col', $this->firstChildTextContent($entryList));
    }

    public function testEntryListFilterUsesDataConfigHelperScope(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_helper', openAccess: true);
        $categoryInput = $this->addNamedFormInput($formSection, 'qa_el_helper_category', 'category');

        $rows = [
            ['qa_answer' => 'shown-one', 'category' => 'keep'],
            ['qa_answer' => 'shown-two', 'category' => 'keep'],
            ['qa_answer' => 'hidden', 'category' => 'skip'],
        ];
        foreach ($rows as $row) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $formPage->getId(),
                'section_id' => (int) $formSection->getId(),
                'form_data' => $row,
            ]));
        }

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();
        $categoryFieldKey = 'section_' . $categoryInput->getId();

        $listPage = $this->pages->createPage('qa_el_helper_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_helper_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionDataConfig($listSection, [[
            'scope' => 'filters',
            'table' => $tableName,
            'retrieve' => 'first',
            'current_user' => false,
        ]]);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->setSectionField(
            $listSection,
            'filter',
            sprintf("%s = '{{filters.%s}}'", $categoryFieldKey, $categoryFieldKey),
            1,
        );

        $cardSection = $this->pages->createSection('qa_el_helper_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $entryList = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-list');
        self::assertNotNull($entryList);

        $texts = $this->childTextContents($entryList);
        sort($texts);
        self::assertSame(['shown-one', 'shown-two'], $texts);
    }

    public function testEntryListDataConfigTableAloneDoesNotLoadRowsWithoutPropertyDataTable(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_legacy_cfg', openAccess: true);

        foreach (['one', 'two'] as $value) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $formPage->getId(),
                'section_id' => (int) $formSection->getId(),
                'form_data' => ['qa_answer' => $value],
            ]));
        }

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        $listPage = $this->pages->createPage('qa_el_legacy_cfg_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_legacy_cfg_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionDataConfig($listSection, [[
            'scope' => 'entries',
            'table' => $tableName,
            'retrieve' => 'all',
            'current_user' => false,
        ]]);

        $cardSection = $this->pages->createSection('qa_el_legacy_cfg_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $entryList = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-list');
        self::assertNotNull($entryList);
        self::assertCount(
            0,
            is_array($entryList['children'] ?? null) ? $entryList['children'] : [],
            'Legacy data_config row binding must not hydrate entry-list without fields.data_table.',
        );
    }

    public function testEntryListPropertyDataTableIsOnlyRowSource(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_dual_cfg', openAccess: true);

        foreach (['alpha', 'beta'] as $value) {
            $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => (int) $formPage->getId(),
                'section_id' => (int) $formSection->getId(),
                'form_data' => ['qa_answer' => $value],
            ]));
        }

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();

        $listPage = $this->pages->createPage('qa_el_dual_cfg_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_dual_cfg_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionDataConfig($listSection, [[
            'scope' => 'entries',
            'table' => $tableName,
            'retrieve' => 'all',
            'current_user' => false,
        ]]);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);

        $cardSection = $this->pages->createSection('qa_el_dual_cfg_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $entryList = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-list');
        self::assertNotNull($entryList);
        self::assertCount(
            2,
            is_array($entryList['children'] ?? null) ? $entryList['children'] : [],
            'fields.data_table must be the sole row source even when legacy data_config entries scope is present.',
        );
    }

    public function testEntryListFilterRejectsUnsafeValueFromDataConfigHelperScope(): void
    {
        [$formPage, $formSection] = $this->pages->createFormPage('qa_el_unsafe_helper', openAccess: true);
        $categoryInput = $this->addNamedFormInput($formSection, 'qa_el_unsafe_category', 'category');

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $formPage->getId(),
            'section_id' => (int) $formSection->getId(),
            'form_data' => [
                'qa_answer' => 'leak',
                'category' => "'; DELETE FROM data_rows WHERE '1'='1",
            ],
        ]));

        $firstRow = $this->em->getRepository(DataRow::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(DataRow::class, $firstRow);
        $dataTable = $firstRow->getDataTable();
        self::assertInstanceOf(DataTable::class, $dataTable);
        $tableName = (string) $dataTable->getName();
        $categoryFieldKey = 'section_' . $categoryInput->getId();

        $listPage = $this->pages->createPage('qa_el_unsafe_helper_list', openAccess: false);
        $this->pages->grantGroupAcl($listPage, $this->subjectGroup(), select: true, insert: false, update: false, delete: false);

        $listSection = $this->pages->createSection('qa_el_unsafe_helper_holder', 'entry-list');
        $this->pages->linkSectionToPage($listPage, $listSection, 10);
        $this->setSectionDataConfig($listSection, [[
            'scope' => 'filters',
            'table' => $tableName,
            'retrieve' => 'first',
            'current_user' => false,
        ]]);
        $this->setSectionField($listSection, 'data_table', (string) $dataTable->getId(), 1);
        $this->setSectionField($listSection, 'own_entries_only', '0', 1);
        $this->setSectionField(
            $listSection,
            'filter',
            sprintf("%s = '{{filters.%s}}'", $categoryFieldKey, $categoryFieldKey),
            1,
        );

        $cardSection = $this->pages->createSection('qa_el_unsafe_helper_card', 'text');
        $this->linkChild($listSection, $cardSection);
        $this->setSectionField($cardSection, 'text', '{{qa_answer}}', 2);

        $this->pages->invalidatePageScopedCaches();

        $token = $this->loginAsQaUser();
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $listPage->getKeyword() . '?preview=true', null, $token),
        );
        $entryList = $this->findSectionByStyle($this->sectionsFromPayload($data), 'entry-list');
        self::assertNotNull($entryList);
        self::assertCount(
            0,
            is_array($entryList['children'] ?? null) ? $entryList['children'] : [],
            'Unsafe helper scope values interpolated into the entry filter must yield no rows.',
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function sectionsFromPayload(array $data): array
    {
        $raw = null;
        if (is_array($data['page'] ?? null) && is_array($data['page']['sections'] ?? null)) {
            $raw = $data['page']['sections'];
        } elseif (is_array($data['sections'] ?? null)) {
            $raw = $data['sections'];
        }

        if (!is_array($raw)) {
            return [];
        }

        $sections = [];
        foreach ($raw as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized = [];
            foreach ($section as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $sections[] = $normalized;
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $section
     * @return list<string>
     */
    private function childTextContents(array $section): array
    {
        $children = is_array($section['children'] ?? null) ? $section['children'] : [];
        $texts = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $content = $this->firstChildTextContent(['children' => [$child]]);
            if (is_string($content)) {
                $texts[] = $content;
            }
        }

        return $texts;
    }

    private function addNamedFormInput(Section $formSection, string $sectionName, string $inputName): Section
    {
        $input = $this->pages->createSection($sectionName, 'input');
        $this->linkChild($formSection, $input);
        $this->setSectionField($input, 'name', $inputName, 1);

        return $input;
    }

    /**
     * @param list<array<string, mixed>> $config
     */
    private function setSectionDataConfig(Section $section, array $config): void
    {
        $section->setDataConfig(json_encode($config, JSON_THROW_ON_ERROR));
        $this->em->persist($section);
        $this->em->flush();
    }

    /**
     * @param array<int|string, mixed> $sections
     * @return array<string, mixed>|null
     */
    private function findSectionByStyle(array $sections, string $styleName): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName) {
                /** @var array<string, mixed> $section */
                return $section;
            }
            if (is_array($section['children'] ?? null)) {
                $found = $this->findSectionByStyle($section['children'], $styleName);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function firstChildTextContent(array $section): ?string
    {
        $children = $section['children'] ?? null;
        if (!is_array($children)) {
            return null;
        }
        $firstChild = $children[0] ?? null;
        if (!is_array($firstChild)) {
            return null;
        }
        $text = $firstChild['text'] ?? null;
        if (!is_array($text)) {
            return null;
        }
        $content = $text['content'] ?? null;

        return is_string($content) ? $content : null;
    }

    private function linkChild(Section $parent, Section $child): void
    {
        $link = new SectionsHierarchy();
        $link->setParentSection($parent);
        $link->setChildSection($child);
        $link->setPosition(10);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function setSectionField(Section $section, string $fieldName, string $content, int $languageId): void
    {
        $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $fieldName]);
        self::assertInstanceOf(Field::class, $field, sprintf('Missing seeded field "%s".', $fieldName));
        $language = $this->em->getRepository(Language::class)->find($languageId);
        self::assertInstanceOf(Language::class, $language);

        $translation = new SectionsFieldsTranslation();
        $translation->setSection($section);
        $translation->setField($field);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'QA baseline subject group missing. Run: composer test:reset-db');

        return $group;
    }
}
