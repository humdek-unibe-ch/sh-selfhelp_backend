<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\FormList;

use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\FormList\FormListColumn;
use App\Service\CMS\FormList\FormListConfig;
use App\Service\CMS\FormList\FormListConfigException;
use App\Service\CMS\FormList\FormListConfigReader;
use App\Service\CMS\FormList\FormListControl;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What an author may store in a list section, and what the reader makes of it.
 * The stored text is the contract between the CMS, the server and the clients,
 * so every rule that protects a visitor (no identity columns, bounded filters,
 * strict shapes) is pinned here without a database.
 */
#[Group('form-list')]
final class FormListConfigReaderTest extends TestCase
{
    private FormListConfigReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FormListConfigReader($this->createStub(EntityManagerInterface::class));
    }

    public function testASectionThatSavedNothingReadsTheDocumentedDefaults(): void
    {
        $config = $this->parse([]);

        self::assertSame('list', $config->key);
        self::assertNull($config->dataTableId, 'The table of the inline form is used.');
        self::assertTrue($config->ownEntriesOnly);
        self::assertTrue($config->allowCreate && $config->allowUpdate && $config->allowDelete);
        self::assertFalse($config->showHistory);
        self::assertSame(FormListConfig::LAYOUT_TABLE, $config->layout);
        self::assertSame(FormListConfig::PRESENTATION_MODAL, $config->formPresentation);
        self::assertSame(25, $config->pageSize);
        self::assertSame([], $config->columns);
        self::assertSame([], $config->controls);
        self::assertSame([], $config->quickActions);
        self::assertTrue($config->fixedFilter->isEmpty());
        self::assertEquals([new DataSort('entry_date', DataSort::DESC)], $config->defaultSorts);
        self::assertSame('Add entry', $config->labels['add']);
        self::assertSame('Delete this entry?', $config->labels['confirm_delete']);
    }

    public function testAFullConfigurationIsReadTypedAndInTheVisitorsLanguage(): void
    {
        $config = $this->parse([
            'list_key' => 'tasks',
            'data_table' => '42',
            'own_entries_only' => '0',
            'show_history' => 'true',
            'list_layout' => 'cards',
            'form_presentation' => 'page',
            'page_size' => '10',
            'list_columns' => json_encode([
                ['field' => 'title', 'label' => ['en' => 'Title', 'de' => 'Titel'], 'sortable' => true, 'searchable' => true],
                ['field' => 'status', 'format' => 'badge'],
                ['field' => 'entry_date', 'format' => 'datetime', 'sortable' => true],
            ], JSON_THROW_ON_ERROR),
            'list_sort' => json_encode([['field' => 'title', 'direction' => 'asc']], JSON_THROW_ON_ERROR),
            'list_controls' => json_encode([
                ['id' => 'state', 'type' => 'select', 'field' => 'status', 'options' => [['value' => 'open', 'label' => 'Open'], ['value' => 'done']]],
                ['id' => 'who', 'type' => 'select', 'field' => 'title', 'options_from' => 'data'],
                ['id' => 'text', 'type' => 'text', 'field' => 'title', 'match' => 'starts_with'],
            ], JSON_THROW_ON_ERROR),
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"status","operator":"neq","value":"archived"}]}',
            'quick_actions' => json_encode([[
                'id' => 'finish',
                'label' => ['en' => 'Finish', 'de' => 'Abschliessen'],
                'confirm' => 'Sure?',
                'set' => ['status' => 'done', 'closed' => true],
                'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
            ]], JSON_THROW_ON_ERROR),
            'label_add' => [1 => 'New task', 3 => 'Neue Aufgabe'],
        ], languageId: 3, locale: 'de-CH');

        self::assertSame('tasks', $config->key);
        self::assertSame(42, $config->dataTableId);
        self::assertFalse($config->ownEntriesOnly);
        self::assertTrue($config->showHistory);
        self::assertSame(FormListConfig::LAYOUT_CARDS, $config->layout);
        self::assertSame(FormListConfig::PRESENTATION_PAGE, $config->formPresentation);
        self::assertSame(10, $config->pageSize);

        self::assertCount(3, $config->columns);
        self::assertSame('Titel', $config->columns[0]->label, 'A language map picks the visitor\'s language.');
        self::assertSame(['title'], $config->searchableFields());
        self::assertSame(['title', 'entry_date'], $config->sortableFields());
        self::assertSame(FormListColumn::FORMAT_BADGE, $config->columns[1]->format);
        self::assertSame(['title', 'status'], $config->projectedFields(), 'Row fields are not columns of the table.');

        self::assertEquals([new DataSort('title', DataSort::ASC)], $config->defaultSorts);

        self::assertCount(3, $config->controls);
        self::assertSame([['value' => 'open', 'label' => 'Open'], ['value' => 'done', 'label' => 'done']], $config->controls[0]->options);
        self::assertTrue($config->controls[1]->optionsFromData);
        self::assertSame('starts_with', $config->controls[2]->match);

        $filter = $config->fixedFilter->children[0];
        self::assertInstanceOf(DataFilter::class, $filter);
        self::assertSame(['status', 'neq', 'archived'], [$filter->column, $filter->operator, $filter->value]);

        $action = $config->quickAction('finish');
        self::assertNotNull($action);
        self::assertSame('Abschliessen', $action->label);
        self::assertSame('Sure?', $action->confirm);
        self::assertSame(['status' => 'done', 'closed' => true], $action->set);
        self::assertFalse($action->when->isEmpty());

        self::assertSame('Neue Aufgabe', $config->labels['add']);
    }

    public function testAnUntranslatedLabelFallsBackToThePropertyLanguage(): void
    {
        $config = $this->parse(['label_add' => [1 => 'New task']], languageId: 3, locale: 'de');

        self::assertSame('New task', $config->labels['add']);
    }

    public function testADataViewOnlyReadsWhateverTheFieldsSay(): void
    {
        $config = $this->parse([
            'data_table' => '7',
            'allow_create' => '1',
            'allow_update' => '1',
            'allow_delete' => '1',
            'show_history' => '1',
            'quick_actions' => '[{"id":"x","label":"X","set":{"a":"b"}}]',
            'form_presentation' => 'modal',
            'detail_url' => '/events/{record_id}',
            'list_columns' => '[{"field":"title"}]',
        ], style: StyleNames::STYLE_DATA_VIEW);

        self::assertFalse($config->allowCreate || $config->allowUpdate || $config->allowDelete || $config->showHistory);
        self::assertSame([], $config->quickActions);
        self::assertSame(FormListConfig::PRESENTATION_PAGE, $config->formPresentation);
        self::assertFalse($config->ownEntriesOnly, 'A data view shows the table, not one person\'s records.');
        self::assertSame('/events/{record_id}', $config->detailUrl);
        self::assertFalse($config->isEditable());
    }

    public function testADataViewMayDrawEveryRecordFromTheSectionsInsideIt(): void
    {
        $config = $this->parse([
            'data_table' => '7',
            'list_layout' => 'custom',
            'list_row_css' => 'grid grid-cols-1 md:grid-cols-2 gap-6',
            'list_columns' => '[{"field":"title"}]',
        ], style: StyleNames::STYLE_DATA_VIEW);

        self::assertSame(FormListConfig::LAYOUT_CUSTOM, $config->layout);
        self::assertTrue($config->usesRowTemplate());
        self::assertSame('grid grid-cols-1 md:grid-cols-2 gap-6', $config->rowCss);
        self::assertSame($config->rowCss, $config->withColumns($config->columns)->rowCss, 'Another set of columns keeps the record classes.');

        $table = $this->parse(['data_table' => '7', 'list_columns' => '[{"field":"title"}]'], style: StyleNames::STYLE_DATA_VIEW);
        self::assertFalse($table->usesRowTemplate(), 'The table layout has no row template.');
        self::assertSame('', $table->rowCss);
    }

    public function testAListWithAFormIgnoresTheRecordClassesOfADataView(): void
    {
        $config = $this->parse(['list_row_css' => 'grid']);

        self::assertSame('', $config->rowCss);
        self::assertFalse($config->usesRowTemplate());
    }

    public function testAListOfOtherPeoplesRecordsNeedsExplicitColumns(): void
    {
        $this->assertRejected(['own_entries_only' => '0', 'data_table' => '3'], 'list_columns', 'Choose the columns');

        $config = $this->parse(['own_entries_only' => '0', 'data_table' => '3', 'list_columns' => '[{"field":"title"}]']);
        self::assertCount(1, $config->columns);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: string, 3: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'key with capitals' => [['list_key' => 'My-List'], 'list_key', 'lowercase letter', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'table that is not an id' => [['data_table' => 'events'], 'data_table', 'table id', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'switch that is not a switch' => [['allow_create' => 'maybe'], 'allow_create', 'Use 1 or 0', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'unknown layout' => [['list_layout' => 'grid'], 'list_layout', 'table", "cards" or "custom', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'custom layout on a list with a form' => [['list_layout' => 'custom'], 'list_layout', 'belongs to a data view', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'unknown presentation' => [['form_presentation' => 'drawer'], 'form_presentation', 'modal', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'page size zero' => [['page_size' => '0'], 'page_size', 'between 1 and 100', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'page size too large' => [['page_size' => '101'], 'page_size', 'between 1 and 100', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'columns that are not json' => [['list_columns' => '[{'], 'list_columns', 'not valid JSON', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'columns as an object' => [['list_columns' => '{"field":"title"}'], 'list_columns', 'Expected a list', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column with unknown property' => [['list_columns' => '[{"field":"title","color":"red"}]'], 'list_columns', 'Unknown property "color"', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column without a field' => [['list_columns' => '[{"label":"x"}]'], 'list_columns', 'names a field', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column listed twice' => [['list_columns' => '[{"field":"title"},{"field":"title"}]'], 'list_columns', 'listed twice', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column naming a person' => [['list_columns' => '[{"field":"user_name"}]'], 'list_columns', 'identifies a person', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column with the user id' => [['list_columns' => '[{"field":"id_users"}]'], 'list_columns', 'identifies a person', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'column with unknown format' => [['list_columns' => '[{"field":"title","format":"html"}]'], 'list_columns', 'Unknown format', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'sortable that is not a boolean' => [['list_columns' => '[{"field":"title","sortable":"yes"}]'], 'list_columns', 'true or false', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'too many columns' => [['list_columns' => '[' . implode(',', array_map(static fn (int $i): string => '{"field":"c' . $i . '"}', range(1, 31))) . ']'], 'list_columns', 'At most 30', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'label that is an array of numbers' => [['list_columns' => '[{"field":"title","label":[1,2]}]'], 'list_columns', 'text per language', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'oversized value' => [['list_columns' => '[{"field":"title","label":"' . str_repeat('x', 20001) . '"}]'], 'list_columns', 'too long', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'sort by a person' => [['list_sort' => '[{"field":"user_code"}]'], 'list_sort', 'cannot be ordered', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'sort with a bad direction' => [['list_sort' => '[{"field":"title","direction":"up"}]'], 'list_sort', 'direction', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'more than three sorts' => [['list_sort' => '[{"field":"a"},{"field":"b"},{"field":"c"},{"field":"d"}]'], 'list_sort', 'At most 3', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'control on a person' => [['list_controls' => '[{"id":"who","type":"text","field":"user_name"}]'], 'list_controls', 'identifies a person', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'control with an unknown type' => [['list_controls' => '[{"id":"c","type":"slider","field":"title"}]'], 'list_controls', 'known type', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'control id that cannot be in a URL' => [['list_controls' => '[{"id":"A b","type":"text","field":"title"}]'], 'list_controls', 'known type', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'control listed twice' => [['list_controls' => '[{"id":"c","type":"text","field":"a"},{"id":"c","type":"text","field":"b"}]'], 'list_controls', 'listed twice', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'control with unknown match' => [['list_controls' => '[{"id":"c","type":"text","field":"a","match":"regex"}]'], 'list_controls', 'Unknown match', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'choices on a text control' => [['list_controls' => '[{"id":"c","type":"text","field":"a","options":[{"value":"x"}]}]'], 'list_controls', 'Only select controls', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'choices from somewhere else' => [['list_controls' => '[{"id":"c","type":"select","field":"a","options_from":"sql"}]'], 'list_controls', 'options_from', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'choice without a value' => [['list_controls' => '[{"id":"c","type":"select","field":"a","options":[{"label":"x"}]}]'], 'list_controls', 'value and an optional label', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'too many controls' => [['list_controls' => '[' . implode(',', array_map(static fn (int $i): string => '{"id":"c' . $i . '","type":"text","field":"a"}', range(1, 13))) . ']'], 'list_controls', 'At most 12', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'fixed filter that is not a group' => [['list_fixed_filter' => '{"column":"a"}'], 'list_fixed_filter', 'group', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'fixed filter with too many conditions' => [['list_fixed_filter' => '{"mode":"and","children":[' . implode(',', array_fill(0, 16, '{"column":"a","operator":"eq","value":"1"}')) . ']}'], 'list_fixed_filter', 'at most 15 conditions', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'quick action without an id' => [['quick_actions' => '[{"label":"x","set":{"a":"b"}}]'], 'quick_actions', 'has an id', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action without a label' => [['quick_actions' => '[{"id":"x","set":{"a":"b"}}]'], 'quick_actions', 'needs a label', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action without an assignment' => [['quick_actions' => '[{"id":"x","label":"X"}]'], 'quick_actions', 'assigns 1 to 10 fields', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action with an empty assignment' => [['quick_actions' => '[{"id":"x","label":"X","set":{}}]'], 'quick_actions', 'assigns 1 to 10 fields', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action assigning the owner' => [['quick_actions' => '[{"id":"x","label":"X","set":{"id_users":3}}]'], 'quick_actions', 'columns of the form', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action assigning a list' => [['quick_actions' => '[{"id":"x","label":"X","set":{"a":["b"]}}]'], 'quick_actions', 'text, number or yes/no', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action listed twice' => [['quick_actions' => '[{"id":"x","label":"X","set":{"a":"b"}},{"id":"x","label":"Y","set":{"a":"c"}}]'], 'quick_actions', 'listed twice', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action with too many conditions' => [['quick_actions' => '[{"id":"x","label":"X","set":{"a":"b"},"when":{"mode":"and","children":[' . implode(',', array_fill(0, 11, '{"column":"a","operator":"eq","value":"1"}')) . ']}}]'], 'quick_actions', 'at most 10 conditions', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'quick action with a condition nested far too deep' => [['quick_actions' => '[{"id":"x","label":"X","set":{"a":"b"},"when":{"mode":"and","children":[{"mode":"or","children":[{"mode":"and","children":[{"column":"a","operator":"eq","value":"1"}]}]}]}}]'], 'quick_actions', 'not valid JSON', StyleNames::STYLE_ENTRY_FORM_LIST];
        yield 'too many quick actions' => [['quick_actions' => '[' . implode(',', array_map(static fn (int $i): string => '{"id":"a' . $i . '","label":"A","set":{"a":"b"}}', range(1, 9))) . ']'], 'quick_actions', 'At most 8', StyleNames::STYLE_ENTRY_FORM_LIST];

        yield 'data view without a table' => [['list_columns' => '[{"field":"title"}]'], 'data_table', 'needs a data table', StyleNames::STYLE_DATA_VIEW];
        yield 'detail link without the record' => [['data_table' => '3', 'detail_url' => '/events'], 'detail_url', 'contains {record_id} once', StyleNames::STYLE_DATA_VIEW];
        yield 'detail link with the record twice' => [['data_table' => '3', 'detail_url' => '/e/{record_id}/{record_id}'], 'detail_url', 'contains {record_id} once', StyleNames::STYLE_DATA_VIEW];
        yield 'detail link to another site' => [['data_table' => '3', 'detail_url' => '//evil.example/{record_id}'], 'detail_url', 'path of this site', StyleNames::STYLE_DATA_VIEW];
        yield 'detail link with a scheme' => [['data_table' => '3', 'detail_url' => 'https://evil.example/{record_id}'], 'detail_url', 'path of this site', StyleNames::STYLE_DATA_VIEW];

        yield 'record classes that close the attribute' => [['data_table' => '3', 'list_row_css' => 'grid" onclick="x'], 'list_row_css', 'plain class names', StyleNames::STYLE_DATA_VIEW];
        yield 'record classes with a tag' => [['data_table' => '3', 'list_row_css' => 'grid <script>'], 'list_row_css', 'plain class names', StyleNames::STYLE_DATA_VIEW];
        yield 'record classes that are too long' => [['data_table' => '3', 'list_row_css' => str_repeat('a ', 151)], 'list_row_css', 'at most 300 characters', StyleNames::STYLE_DATA_VIEW];
    }

    /**
     * @param array<string, string> $raw
     */
    #[DataProvider('invalidConfigurations')]
    public function testAnInvalidConfigurationIsRefusedWithTheFieldThatIsWrong(array $raw, string $field, string $fragment, string $style): void
    {
        $this->assertRejected($raw, $field, $fragment, $style);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        try {
            $this->parse(['page_size' => '0', 'list_layout' => 'grid', 'list_columns' => '[{"field":"user_name"}]']);
            self::fail('The configuration has three problems.');
        } catch (FormListConfigException $e) {
            self::assertSame(400, $e->getCode());
            self::assertEqualsCanonicalizing(['page_size', 'list_layout', 'list_columns'], array_column($e->errors(), 'field'));
        }
    }

    public function testTheDateOfAnEntryMayBeShownSortedAndFiltered(): void
    {
        $config = $this->parse([
            'list_columns' => '[{"field":"entry_date","sortable":true}]',
            'list_sort' => '[{"field":"record_id","direction":"desc"}]',
            'list_controls' => '[{"id":"when","type":"date_range","field":"entry_date"}]',
        ]);

        self::assertSame(FormListControl::TYPE_DATE_RANGE, $config->controls[0]->type);
        self::assertSame(['entry_date'], $config->sortableFields());
        self::assertSame([], $config->projectedFields(), 'Only row fields are shown: the table\'s own columns need no cells.');
    }

    public function testAColumnMapMayNameTheVisitorsLanguageOrAnyoneElseAsAFallback(): void
    {
        $config = $this->parse(['list_columns' => '[{"field":"title","label":{"fr":"Titre"}}]'], languageId: 2, locale: 'de');

        self::assertSame('Titre', $config->columns[0]->label, 'A map without the visitor\'s language shows its first entry rather than nothing.');
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * @param array<string, string|array<int, string>> $raw field => content, or field => language id => content
     */
    private function parse(array $raw, string $style = StyleNames::STYLE_ENTRY_FORM_LIST, int $languageId = 1, string $locale = 'en'): FormListConfig
    {
        $stored = [];
        foreach ($raw as $field => $content) {
            $stored[$field] = is_array($content) ? $content : [1 => $content];
        }

        return $this->reader->parse(100, $style, $stored, $languageId, $locale);
    }

    /**
     * @param array<string, string> $raw
     */
    private function assertRejected(array $raw, string $field, string $fragment, string $style = StyleNames::STYLE_ENTRY_FORM_LIST): void
    {
        try {
            $this->parse($raw, $style);
        } catch (FormListConfigException $e) {
            $messages = [];
            foreach ($e->errors() as $error) {
                $messages[$error['field']][] = $error['message'];
            }
            self::assertArrayHasKey($field, $messages, 'The problem is reported on the field that holds it. Got: ' . json_encode($messages));
            self::assertStringContainsString($fragment, implode(' | ', $messages[$field]));

            return;
        }
        self::fail('The configuration should have been refused.');
    }
}
