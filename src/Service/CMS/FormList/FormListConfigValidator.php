<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\Field;
use App\Entity\Section;
use App\Repository\SectionRepository;
use App\Service\CMS\Admin\PageExportImportService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataQuery\DataColValueType;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataQuery\DataSearch;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Author-time check of a list's configuration: it runs when a list section is
 * saved and when a page or section bundle is imported, so a mistake is told to
 * the author instead of showing as a broken list to the visitors.
 *
 * {@see FormListConfigReader} checks everything that needs no table (shapes,
 * enumerations, limits). This class checks what needs the table:
 *
 *  - every column, control, sort, filter and quick-action field exists;
 *  - a control fits the type of its column (a number range on a number column);
 *  - the fixed filter, the conditions of the quick actions, the default order
 *    and the search compile against the table, so the query engine accepts them;
 *  - a quick action only assigns values its column can read.
 *
 * The columns a table will have are its stored columns plus the inputs of its
 * form: the columns of a form that was never submitted do not exist yet, and a
 * list is usually configured before the first record.
 *
 * Nothing is checked when no table can be found (the list has no form yet, or
 * a bundle is imported before its tables exist): the list is then checked for
 * shape only, and the render-time reader checks it again.
 */
class FormListConfigValidator extends BaseService
{
    /** What an author calls the row fields of a record. */
    private const ROW_FIELD_LABELS = [
        'record_id' => 'Record number',
        'entry_date' => 'Entry date',
        'id_users' => 'User id',
        'user_name' => 'User name',
        'user_code' => 'User code',
        'triggerType' => 'Trigger type',
        'id_action_trigger_types' => 'Trigger type id',
    ];

    public function __construct(
        private readonly FormListConfigReader $reader,
        private readonly FormListGuard $guard,
        private readonly DataQueryEngine $engine,
        private readonly DataService $dataService,
        private readonly FormFieldKeyResolver $keyResolver,
        private readonly SectionRepository $sectionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Refuse a section save whose result would be an invalid list.
     *
     * @param list<array<string, mixed>> $propertyFields property fields as sent by the editor (`fieldId`, `value`)
     * @throws FormListConfigException 400 with every problem found
     */
    public function assertSectionSaveValid(Section $section, array $propertyFields): void
    {
        $style = $section->getStyle()?->getName();
        if ($style === null || !in_array($style, StyleNames::LIST_STYLE_NAMES, true) || $propertyFields === []) {
            return;
        }

        $values = [];
        foreach ($propertyFields as $propertyField) {
            $fieldId = $this->asInt($propertyField['fieldId'] ?? null);
            if ($fieldId > 0) {
                $values[$fieldId] = $propertyField['value'] ?? null;
            }
        }
        if ($values === []) {
            return;
        }

        $sectionId = (int) $section->getId();
        $raw = $this->reader->rawFields($sectionId);

        // A save of the title or the spacing is not held up by a list that needs attention elsewhere.
        $configFields = array_keys(FormListConfig::defaultsFor($style));
        $touched = false;
        foreach ($this->entityManager->getRepository(Field::class)->findBy(['id' => array_keys($values)]) as $field) {
            $name = (string) $field->getName();
            $raw[$name][1] = $this->textOf($values[(int) $field->getId()] ?? null);
            $touched = $touched || in_array($name, $configFields, true);
        }
        if (!$touched) {
            return;
        }

        $problems = $this->problems($sectionId, $style, $raw);
        if ($problems !== []) {
            throw new FormListConfigException($problems);
        }
    }

    /**
     * The problems of a list inside an import payload. Its table does not exist
     * yet (the sections are created by the import), so only the shape is checked.
     *
     * @param array<array-key, mixed> $fields imported fields: field name => locale => `{content, ...}`
     * @return list<array{field: string, message: string}>
     */
    public function importProblems(string $style, array $fields): array
    {
        if (!in_array($style, StyleNames::LIST_STYLE_NAMES, true)) {
            return [];
        }

        $raw = [];
        foreach ($fields as $name => $localeData) {
            if (!is_array($localeData)) {
                continue;
            }
            // Property fields carry the locale "all" (language 1); a translated field is checked in its first locale.
            $translation = $localeData['all'] ?? ($localeData === [] ? null : reset($localeData));
            if (is_array($translation) && array_key_exists('content', $translation)) {
                $content = $this->textOf($translation['content']);
                // A bundle names the table by its owner form (`@section:<form name>`); the import turns that into a table id.
                if ($name === 'data_table' && $this->isOwnerToken($content)) {
                    $content = '1';
                }
                $raw[(string) $name][1] = $content;
            }
        }

        try {
            $this->reader->parse(0, $style, $raw, 1);
        } catch (FormListConfigException $e) {
            return $e->errors();
        }

        return [];
    }

    /**
     * Everything wrong with the configuration a list section would have.
     *
     * @param array<string, array<int, string>> $raw complete stored fields after the change: field name => language id => content
     * @return list<array{field: string, message: string}>
     */
    public function problems(int $sectionId, string $style, array $raw): array
    {
        try {
            $config = $this->reader->parse($sectionId, $style, $raw, 1);
        } catch (FormListConfigException $e) {
            return $e->errors();
        }

        $source = $this->sourceOf($config);
        if ($source === null) {
            return [];
        }

        $columns = $this->columnsOf($source['tableId'], $source['tableName']);
        if ($columns === []) {
            return [];
        }

        return $this->tableProblems($config, $source['tableId'] ?? 0, $columns);
    }

    /**
     * The table the list reads: its own `data_table`, otherwise the table of the
     * form inside it. The table row may not exist yet (the form was never
     * submitted); its name is the form section's id.
     *
     * @return array{tableId: ?int, tableName: ?string}|null
     */
    private function sourceOf(FormListConfig $config): ?array
    {
        return $this->sourceFor($config->sectionId, $config->dataTableId);
    }

    /**
     * {@see self::sourceOf()} for a list whose configuration may not be valid (yet).
     *
     * @return array{tableId: ?int, tableName: ?string}|null
     */
    private function sourceFor(int $sectionId, ?int $dataTableId): ?array
    {
        if ($dataTableId !== null) {
            $table = $this->dataService->getDataTableById($dataTableId);

            return $table === null ? null : ['tableId' => (int) $table->getId(), 'tableName' => (string) $table->getName()];
        }

        foreach ($this->sectionRepository->getPageIdsContainingSection($sectionId) as $pageId) {
            $rows = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);
            $formSectionId = FormListLayout::inlineFormSectionId($rows, $sectionId);
            if ($formSectionId !== null) {
                return ['tableId' => $this->guard->tableIdOfForm($formSectionId), 'tableName' => (string) $formSectionId];
            }
        }

        return null;
    }

    /**
     * What the editor of a list offers its author: the table the list reads and
     * the fields of that table.
     *
     * A list names its columns by their immutable key (`section_<input id>`),
     * which nobody can know by heart; the editor draws its pickers from this
     * answer instead. It is exactly the set the save check accepts (the stored
     * columns plus the columns the form's inputs will create), each with the
     * label an author knows (the input name) and the type that decides which
     * controls fit it. Row fields (the date of an entry, the record number, the
     * author of a record) are listed with the places they may be used in.
     *
     * Null for a section that is not a list, or whose table cannot be found yet.
     *
     * @return array{data_table: array{id: int, name: string}|null, form_section_id: int|null, columns: list<array{field: string, label: string, type: string}>, row_fields: list<array{field: string, label: string, type: string, column: bool, sort: bool, filter: bool}>}|null
     */
    public function catalogOf(Section $section): ?array
    {
        $style = $section->getStyle()?->getName();
        if ($style === null || !in_array($style, StyleNames::LIST_STYLE_NAMES, true)) {
            return null;
        }

        $sectionId = (int) $section->getId();
        $tableText = trim($this->reader->rawFields($sectionId)['data_table'][1] ?? '');
        $explicitTable = ctype_digit($tableText) && (int) $tableText > 0 ? (int) $tableText : null;
        $source = $this->sourceFor($sectionId, $explicitTable);
        if ($source === null) {
            return null;
        }

        $tableName = (string) $source['tableName'];
        $names = ctype_digit($tableName) ? $this->keyResolver->getFieldKeyToName($tableName) : [];

        $columns = [];
        $stored = $this->columnsOf($source['tableId'], $tableName);
        // The inputs of the form come first, in the order of the form; stored columns that no input owns follow.
        foreach ($names as $key => $name) {
            if (isset($stored[$key])) {
                $columns[] = ['field' => $key, 'label' => $name, 'type' => $stored[$key]['type']];
            }
        }
        foreach ($stored as $key => $column) {
            if (!isset($names[$key])) {
                $columns[] = ['field' => $key, 'label' => $key, 'type' => $column['type']];
            }
        }

        $rowFields = [];
        foreach (DataQueryEngine::rowFields() as $field => $type) {
            $rowFields[] = [
                'field' => $field,
                'label' => self::ROW_FIELD_LABELS[$field] ?? $field,
                'type' => $type,
                'column' => in_array($field, FormListConfigReader::VISIBLE_ROW_FIELDS, true),
                'sort' => in_array($field, FormListConfigReader::SORTABLE_ROW_FIELDS, true),
                'filter' => true,
            ];
        }

        return [
            'data_table' => $source['tableId'] === null ? null : ['id' => $source['tableId'], 'name' => $tableName],
            'form_section_id' => $explicitTable === null && ctype_digit($tableName) ? (int) $tableName : null,
            'columns' => $columns,
            'row_fields' => $rowFields,
        ];
    }

    /**
     * The stored columns of the table and the columns its form's inputs will create.
     *
     * @return array<string, array{id: int, type: string}>
     */
    private function columnsOf(?int $tableId, ?string $tableName): array
    {
        $columns = $tableId !== null ? $this->engine->columnsOf($tableId) : [];

        if ($tableName !== null && ctype_digit($tableName)) {
            $types = $this->keyResolver->getFieldKeyToValueType($tableName);
            foreach (array_keys($this->keyResolver->getFieldKeyToName($tableName)) as $key) {
                if (!isset($columns[$key])) {
                    $columns[$key] = ['id' => 0, 'type' => $types[$key] ?? LookupService::DATA_COL_VALUE_TYPES_TEXT];
                }
            }
        }

        return $columns;
    }

    /**
     * @param array<string, array{id: int, type: string}> $columns
     * @return list<array{field: string, message: string}>
     */
    private function tableProblems(FormListConfig $config, int $tableId, array $columns): array
    {
        $problems = [];
        $unknown = static fn (string $field): bool => !isset($columns[$field]) && !DataQueryEngine::isRowField($field);

        foreach ($config->columns as $column) {
            if ($unknown($column->field)) {
                $problems[] = ['field' => 'list_columns', 'message' => sprintf('The column "%s" is not a field of the table.', $column->field)];
            } elseif ($column->searchable && !isset($columns[$column->field])) {
                $problems[] = ['field' => 'list_columns', 'message' => sprintf('"%s" cannot be searched.', $column->field)];
            }
        }

        foreach ($config->defaultSorts as $sort) {
            if ($unknown($sort->column)) {
                $problems[] = ['field' => 'list_sort', 'message' => sprintf('The order by "%s": it is not a field of the table.', $sort->column)];
            }
        }

        foreach ($config->controls as $control) {
            $type = $control->field === 'entry_date'
                ? LookupService::DATA_COL_VALUE_TYPES_DATETIME
                : ($columns[$control->field]['type'] ?? null);
            if ($type === null) {
                $problems[] = ['field' => 'list_controls', 'message' => sprintf('The control "%s": "%s" is not a field of the table.', $control->id, $control->field)];
                continue;
            }
            $fits = match ($control->type) {
                FormListControl::TYPE_NUMBER_RANGE => $type === LookupService::DATA_COL_VALUE_TYPES_NUMBER,
                FormListControl::TYPE_DATE_RANGE => in_array($type, [LookupService::DATA_COL_VALUE_TYPES_DATE, LookupService::DATA_COL_VALUE_TYPES_DATETIME], true),
                FormListControl::TYPE_BOOLEAN => $type === LookupService::DATA_COL_VALUE_TYPES_BOOLEAN,
                default => true,
            };
            if (!$fits) {
                $problems[] = ['field' => 'list_controls', 'message' => sprintf(
                    'The control "%s" is a %s control but "%s" holds %s values; set the type of the column first.',
                    $control->id,
                    $control->type,
                    $control->field,
                    $type,
                )];
            }
        }

        if ($problems !== []) {
            return $problems; // the engine would only repeat the same findings
        }

        $fixed = $this->compileProblem($tableId, $columns, $config->fixedFilter);
        if ($fixed !== null) {
            $problems[] = ['field' => 'list_fixed_filter', 'message' => $fixed];
        }

        $searchable = $config->searchableFields();
        $orderAndSearch = $this->compileProblem(
            $tableId,
            $columns,
            new DataFilterGroup(),
            $config->defaultSorts,
            $searchable === [] ? null : new DataSearch('xx', $searchable),
        );
        if ($orderAndSearch !== null) {
            $problems[] = ['field' => 'list_columns', 'message' => $orderAndSearch];
        }

        foreach ($config->quickActions as $action) {
            foreach ($action->set as $field => $value) {
                $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                if (!isset($columns[$field])) {
                    $problems[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" assigns "%s", which is not a field of the form.', $action->id, $field)];
                } elseif (!DataColValueType::reads($columns[$field]['type'], $text)) {
                    $problems[] = ['field' => 'quick_actions', 'message' => sprintf(
                        'The quick action "%s" assigns "%s" to "%s", which holds %s values.',
                        $action->id,
                        $text,
                        $field,
                        $columns[$field]['type'],
                    )];
                }
            }
            if (!$action->when->isEmpty()) {
                $when = $this->compileProblem($tableId, $columns, $action->when);
                if ($when !== null) {
                    $problems[] = ['field' => 'quick_actions', 'message' => sprintf('The condition of "%s": %s', $action->id, $when)];
                }
            }
        }

        return $problems;
    }

    /**
     * The engine's complaint about a query, or null when it accepts it.
     *
     * @param array<string, array{id: int, type: string}> $columns
     * @param list<DataSort> $sorts
     */
    private function compileProblem(int $tableId, array $columns, DataFilterGroup $filter, array $sorts = [], ?DataSearch $search = null): ?string
    {
        try {
            $this->engine->compile(
                new DataQuery(tableId: $tableId, filter: $filter, search: $search, sorts: $sorts, limit: 1, columns: []),
                $columns,
            );
        } catch (DataQueryException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * `@section:<form name>`: the portable name of a table in a bundle.
     */
    private function isOwnerToken(string $text): bool
    {
        $prefix = PageExportImportService::OWNER_TOKEN_PREFIX;
        $text = trim($text);

        return str_starts_with($text, $prefix) && strlen($text) > strlen($prefix);
    }

    private function textOf(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
