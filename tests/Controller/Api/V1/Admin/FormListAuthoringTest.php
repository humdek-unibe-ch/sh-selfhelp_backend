<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Field;
use App\Entity\Page;
use App\Entity\Section;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * An author who saves or imports a list is told what is wrong at once, with the
 * field that holds the problem; nothing broken is stored. Saves that do not
 * touch the list's configuration are never held up by it.
 */
#[PhpUnitGroup('form-list')]
final class FormListAuthoringTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testAValidConfigurationIsSavedAsWritten(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_la_valid');
        $this->grid->column($tableId, 'seats', 'number');
        $token = $this->loginAsQaAdmin();

        $columns = '[{"field":"title","sortable":true,"searchable":true},{"field":"seats","format":"number"},{"field":"entry_date"}]';
        $envelope = $this->save($token, $page, $list, [
            'list_columns' => $columns,
            'list_controls' => '[{"id":"state","type":"select","field":"status","options_from":"data"},{"id":"seats","type":"number_range","field":"seats"}]',
            'list_sort' => '[{"field":"title","direction":"asc"}]',
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"status","operator":"neq","value":"archived"}]}',
            'quick_actions' => '[{"id":"done","label":"Done","set":{"status":"done","seats":"0"},"when":{"mode":"and","children":[{"column":"status","operator":"eq","value":"open"}]}}]',
            'page_size' => '10',
        ]);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame($columns, $this->stored($list, 'list_columns'));
        self::assertSame('10', $this->stored($list, 'page_size'));
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'shape: columns that are not json' => [['list_columns' => '[{'], 'list_columns', 'not valid JSON'];
        yield 'shape: a column that names a person' => [['list_columns' => '[{"field":"user_name"}]'], 'list_columns', 'identifies a person'];
        yield 'table: a column the table does not have' => [['list_columns' => '[{"field":"nope"}]'], 'list_columns', '"nope" is not a field of the table'];
        yield 'table: a search on a row field' => [['list_columns' => '[{"field":"entry_date","searchable":true}]'], 'list_columns', 'cannot be searched'];
        yield 'table: an order by an unknown column' => [['list_sort' => '[{"field":"nope","direction":"asc"}]'], 'list_sort', 'not a field of the table'];
        yield 'table: a control on an unknown column' => [['list_controls' => '[{"id":"c","type":"text","field":"nope"}]'], 'list_controls', '"nope" is not a field of the table'];
        yield 'table: a number range on a text column' => [['list_controls' => '[{"id":"c","type":"number_range","field":"title"}]'], 'list_controls', 'set the type of the column first'];
        yield 'table: a fixed filter on an unknown column' => [['list_fixed_filter' => '{"mode":"and","children":[{"column":"nope","operator":"eq","value":"x"}]}'], 'list_fixed_filter', 'nope'];
        yield 'table: a number compared with text' => [['list_fixed_filter' => '{"mode":"and","children":[{"column":"seats","operator":"gt","value":"many"}]}'], 'list_fixed_filter', 'seats'];
        yield 'table: a quick action assigning an unknown column' => [['quick_actions' => '[{"id":"x","label":"X","set":{"nope":"1"}}]'], 'quick_actions', 'not a field of the form'];
        yield 'table: a quick action assigning text to a number' => [['quick_actions' => '[{"id":"x","label":"X","set":{"seats":"many"}}]'], 'quick_actions', 'holds number values'];
        yield 'table: a quick action condition on an unknown column' => [['quick_actions' => '[{"id":"x","label":"X","set":{"status":"a"},"when":{"mode":"and","children":[{"column":"nope","operator":"eq","value":"1"}]}}]'], 'quick_actions', 'The condition of "x"'];
    }

    /**
     * @param array<string, string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfigurations')]
    public function testABrokenConfigurationIsRefusedAndNothingIsStored(array $values, string $field, string $fragment): void
    {
        [$page, $list, $tableId] = $this->formList('qa_la_invalid');
        $this->grid->column($tableId, 'seats', 'number');
        $token = $this->loginAsQaAdmin();
        $changed = array_key_first($values);
        self::assertIsString($changed);
        $before = $this->stored($list, $changed);

        $envelope = $this->save($token, $page, $list, $values);

        self::assertSame(400, $envelope['status'] ?? null, (string) json_encode($envelope));
        $errors = $this->errorsOf($envelope);
        self::assertNotSame([], $errors, 'The answer lists the problems.');
        $found = array_filter($errors, static fn (array $error): bool => $error['field'] === $field && str_contains($error['message'], $fragment));
        self::assertNotSame([], $found, sprintf('Expected a problem on "%s" containing "%s", got %s.', $field, $fragment, (string) json_encode($errors)));
        self::assertSame($before, $this->stored($list, $changed), 'A refused save leaves the section unchanged.');
    }

    public function testEveryProblemIsReportedInOneAnswer(): void
    {
        [$page, $list] = $this->formList('qa_la_all');
        $token = $this->loginAsQaAdmin();

        $envelope = $this->save($token, $page, $list, [
            'list_columns' => '[{"field":"nope"}]',
            'list_sort' => '[{"field":"gone","direction":"asc"}]',
        ]);

        self::assertSame(400, $envelope['status'] ?? null);
        $fields = array_column($this->errorsOf($envelope), 'field');
        sort($fields);
        self::assertSame(['list_columns', 'list_sort'], $fields);
    }

    public function testASaveThatDoesNotTouchTheConfigurationIsNeverHeldUp(): void
    {
        [$page, $list] = $this->formList('qa_la_untouched');
        // A list that was valid when it was written and whose table has since lost a column.
        $this->pages->setSectionField($list, 'list_columns', '[{"field":"gone_column"}]');
        $token = $this->loginAsQaAdmin();

        $envelope = $this->save($token, $page, $list, ['spacing' => '']);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame('[{"field":"gone_column"}]', $this->stored($list, 'list_columns'));
    }

    public function testADataViewNeedsATableToBeSaved(): void
    {
        [$page, $view] = $this->dataView('qa_la_view', openAccess: false);
        $token = $this->loginAsQaAdmin();

        $envelope = $this->save($token, $page, $view, ['data_table' => '']);

        self::assertSame(400, $envelope['status'] ?? null);
        self::assertNotSame([], array_filter($this->errorsOf($envelope), static fn (array $error): bool => $error['field'] === 'data_table'));
    }

    public function testASectionImportWithABrokenListIsRefusedBeforeAnythingIsCreated(): void
    {
        [$page] = $this->formList('qa_la_import');
        $token = $this->loginAsQaAdmin();
        $before = $this->sectionsOnPage($page);

        $envelope = $this->import($token, $page, [
            'section_name' => 'qa_la_imported_broken',
            'style_name' => 'entry-form-list',
            'fields' => ['list_columns' => ['all' => ['content' => '[{"field":"user_name"}]']]],
        ]);

        self::assertSame(422, $envelope['status'] ?? null, 'An import answers every validation problem the way imports always do: 422 with per-node errors.');
        self::assertStringContainsString('invalid_list_config', json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('$.sections[0].fields.list_columns', json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->sectionsOnPage($page), 'A refused import creates no section.');
    }

    public function testASectionImportWithAValidListIsAccepted(): void
    {
        [$page] = $this->formList('qa_la_import_ok');
        $token = $this->loginAsQaAdmin();
        $before = $this->sectionsOnPage($page);

        $envelope = $this->import($token, $page, [
            'section_name' => 'qa_la_imported_ok',
            'style_name' => 'entry-form-list',
            'fields' => [
                'list_columns' => ['all' => ['content' => '[{"field":"title"}]']],
                'quick_actions' => ['all' => ['content' => '[{"id":"x","label":"X","set":{"status":"done"}}]']],
            ],
        ]);

        $this->assertEnvelopeSuccess($envelope);
        self::assertSame($before + 1, $this->sectionsOnPage($page));
    }

    public function testTheEditorIsToldWhichFieldsTheListMayName(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_la_catalog');
        $this->grid->column($tableId, 'seats', 'number');
        $token = $this->loginAsQaAdmin();

        $source = $this->listSource($token, $page, $list);

        self::assertSame(['id' => $tableId, 'name' => (string) $form->getId()], $source['data_table']);
        self::assertSame($form->getId(), $source['form_section_id'], 'The list is the form: form_section_id is the list itself.');
        $types = [];
        foreach ($source['columns'] as $column) {
            $types[$column['field']] = $column['type'];
        }
        self::assertSame('text', $types['title'] ?? null);
        self::assertSame('number', $types['seats'] ?? null, 'The type of a column decides which controls fit it.');

        $rowFields = [];
        foreach ($source['row_fields'] as $rowField) {
            $rowFields[$rowField['field']] = $rowField;
        }
        self::assertTrue($rowFields['entry_date']['column'] && $rowFields['entry_date']['sort'] && $rowFields['entry_date']['filter']);
        self::assertTrue($rowFields['record_id']['sort'] && !$rowFields['record_id']['column']);
        self::assertTrue($rowFields['user_name']['filter'] && !$rowFields['user_name']['column'] && !$rowFields['user_name']['sort'], 'A person is never a column; only the author may filter on one.');
    }

    public function testTheCatalogOfADataViewFollowsItsOwnTableAndAFormWithoutRecordsOffersItsInputs(): void
    {
        [$page, $view] = $this->dataView('qa_la_catalog_view', openAccess: false);
        $token = $this->loginAsQaAdmin();

        $source = $this->listSource($token, $page, $view);
        self::assertNull($source['form_section_id'], 'A data view reads the table it names, not a form.');
        $fields = array_column($source['columns'], 'field');
        sort($fields);
        self::assertSame(['city', 'note', 'published', 'seats', 'starts', 'title'], $fields);

        // A list nobody has submitted yet has no table; its own inputs still tell the editor what it may use.
        $page = $this->pages->createPage('qa_la_catalog_fresh', false);
        $list = $this->pages->createSection('qa_la_catalog_fresh_list', 'entry-form-list');
        $this->pages->linkSectionToPage($page, $list, 10);
        $title = $this->formInput($list, 'qa_la_catalog_fresh_title', 'text-input', 'title', 10);
        $note = $this->formInput($list, 'qa_la_catalog_fresh_note', 'text-input', 'note', 20);

        $fresh = $this->listSource($token, $page, $list);
        self::assertNull($fresh['data_table'], 'The list was never submitted: no table yet.');
        self::assertSame((int) $list->getId(), $fresh['form_section_id']);
        self::assertSame([
            ['field' => 'section_' . $title->getId(), 'label' => 'title', 'type' => 'text'],
            ['field' => 'section_' . $note->getId(), 'label' => 'note', 'type' => 'text'],
        ], $fresh['columns']);
    }

    public function testANestedFormStyleUnderAListIsRefusedOnImport(): void
    {
        [$page] = $this->formList('qa_la_nest');
        $token = $this->loginAsQaAdmin();
        $before = $this->sectionsOnPage($page);

        $envelope = $this->import($token, $page, [
            'section_name' => 'qa_la_nest_list',
            'style_name' => 'entry-form-list',
            'children' => [[
                'section_name' => 'qa_la_nest_form',
                'style_name' => 'form-record',
                'children' => [],
            ]],
        ]);

        self::assertSame(422, $envelope['status'] ?? null, (string) json_encode($envelope));
        self::assertStringContainsString('form_inside_list', json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->sectionsOnPage($page));
    }

    public function testQuickActionsOnAForeignTableListUseTheSourceColumns(): void
    {
        [$pageA, $listA, $tableId, , $keys] = $this->declaredList('qa_la_src_a', [
            'title' => [],
            'status' => [],
        ]);
        $pageB = $this->pages->createPage('qa_la_src_b', false);
        $listB = $this->pages->createSection('qa_la_src_b_list', 'entry-form-list');
        $this->pages->linkSectionToPage($pageB, $listB, 10);
        $this->pages->setSectionField($listB, 'data_table', (string) $tableId);
        $this->pages->setSectionField($listB, 'own_entries_only', '0');
        $this->pages->setSectionField($listB, 'allow_create', '0');
        $this->pages->setSectionField($listB, 'allow_update', '0');
        $this->pages->setSectionField($listB, 'allow_delete', '1');
        $this->pages->setSectionField($listB, 'list_columns', json_encode([
            ['field' => $keys['title'], 'label' => 'Title'],
            ['field' => $keys['status'], 'label' => 'Status'],
        ], JSON_THROW_ON_ERROR));
        $token = $this->loginAsQaAdmin();

        $source = $this->listSource($token, $pageB, $listB);
        self::assertNull($source['form_section_id']);
        $fields = array_column($source['columns'], 'field');
        self::assertContains($keys['title'], $fields);
        self::assertContains($keys['status'], $fields);

        $envelope = $this->save($token, $pageB, $listB, [
            'quick_actions' => json_encode([
                ['id' => 'approve', 'label' => 'Approve', 'set' => [$keys['status'] => 'approved']],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->assertEnvelopeSuccess($envelope);
        unset($pageA, $listA);
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * What the list editor is offered: `list_source` of the section details.
     *
     * @return array{data_table: array{id: int, name: string}|null, form_section_id: int|null, columns: list<array{field: string, label: string, type: string}>, row_fields: list<array{field: string, label: string, type: string, column: bool, sort: bool, filter: bool}>}
     */
    private function listSource(string $token, Page $page, Section $list): array
    {
        $envelope = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $list->getId()), null, $token);
        $this->assertEnvelopeSuccess($envelope);
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);
        self::assertArrayHasKey('list_source', $data);
        $source = $data['list_source'];
        self::assertIsArray($source);
        /** @var array{data_table: array{id: int, name: string}|null, form_section_id: int|null, columns: list<array{field: string, label: string, type: string}>, row_fields: list<array{field: string, label: string, type: string, column: bool, sort: bool, filter: bool}>} $source */

        return $source;
    }

    /**
     * @param array<string, string> $values property field name => stored text
     * @return array<string, mixed>
     */
    private function save(string $token, Page $page, Section $section, array $values): array
    {
        $propertyFields = [];
        foreach ($values as $name => $value) {
            $field = $this->em->getRepository(Field::class)->findOneBy(['name' => $name]);
            self::assertInstanceOf(Field::class, $field, sprintf('The field "%s" is seeded.', $name));
            $propertyFields[] = ['fieldId' => (int) $field->getId(), 'value' => $value];
        }

        return $this->jsonRequest(
            'PUT',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $section->getId()),
            ['contentFields' => [], 'propertyFields' => $propertyFields],
            $token,
        );
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function import(string $token, Page $page, array $section): array
    {
        return $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/sections/import', $page->getId()),
            ['position' => 0, 'sections' => [$section]],
            $token,
        );
    }

    private function stored(Section $section, string $field): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT t.content FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections = :section AND f.name = :name AND t.id_languages = 1',
            ['section' => $section->getId(), 'name' => $field],
        );

        return $value === false ? null : self::coerceString($value);
    }

    private function sectionsOnPage(Page $page): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM rel_pages_sections WHERE id_pages = :page', ['page' => $page->getId()]));
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<array{field: string, message: string}>
     */
    private function errorsOf(array $envelope): array
    {
        $data = $envelope['data'] ?? [];
        $errors = is_array($data) ? ($data['errors'] ?? []) : [];
        $list = [];
        foreach (is_array($errors) ? $errors : [] as $error) {
            if (is_array($error) && is_string($error['field'] ?? null) && is_string($error['message'] ?? null)) {
                $list[] = ['field' => $error['field'], 'message' => $error['message']];
            }
        }

        return $list;
    }
}
