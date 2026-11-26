<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\Language;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\Effective\EffectivePage;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataFilterJson;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataQuery\DataQueryLimits;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\Core\BaseService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads and checks the stored configuration of a list section. The only place
 * that knows how the section fields of `entry-form-list` and `data-view` are
 * stored and what they may contain.
 *
 * What is checked here needs no table: shapes, enumerations, limits and
 * characters. What needs the table (does the column exist, does the value fit
 * its type) is checked by {@see FormListConfigValidator}.
 */
class FormListConfigReader extends BaseService
{
    /** Class names only: no quotes, angle brackets, backslashes or control characters. */
    private const ROW_CSS_PATTERN = '/^[^"\'<>\\\\\x00-\x1f]+$/';

    private const ID_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const FIELD_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
    private const MAX_LABEL_LENGTH = 100;
    private const MAX_CONFIRM_LENGTH = 200;
    private const MAX_ASSIGNED_FIELDS = 10;
    private const MAX_JSON_LENGTH = 20000;
    private const MAX_FIXED_FILTER_NODES = 15;
    private const MAX_WHEN_NODES = 10;
    private const MAX_WHEN_DEPTH = 2;

    private const COLUMN_KEYS = ['field', 'label', 'sortable', 'searchable', 'format'];
    private const SORT_KEYS = ['field', 'direction'];
    private const CONTROL_KEYS = ['id', 'type', 'field', 'label', 'options', 'options_from', 'match'];
    private const ACTION_KEYS = ['id', 'label', 'confirm', 'set', 'when'];

    /**
     * Row fields a list may show or offer a control on: the date of an entry. The others
     * (`user_name`, `user_code`, `id_users`) identify people; a list shows what the
     * participants entered, not who they are, and a visitor control on them would let
     * anyone find out who wrote what. They stay available to the author's own fixed filter.
     */
    public const VISIBLE_ROW_FIELDS = ['entry_date'];

    /** Row fields a default order may use: the date and the record number say nothing about a person. */
    public const SORTABLE_ROW_FIELDS = ['entry_date', 'record_id'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The style name of a section, or null when the section does not exist.
     */
    public function styleOf(int $sectionId): ?string
    {
        $name = $this->entityManager->getConnection()->fetchOne(
            'SELECT s.name FROM sections sec JOIN styles s ON s.id = sec.id_styles WHERE sec.id = :id',
            ['id' => $sectionId],
            ['id' => ParameterType::INTEGER],
        );

        return is_string($name) ? $name : null;
    }

    /**
     * Stored content of every field of a section: field name => language id => content.
     *
     * @return array<string, array<int, string>>
     */
    public function rawFields(int $sectionId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.name AS name, t.id_languages AS language_id, t.content AS content'
            . ' FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections = :section',
            ['section' => $sectionId],
            ['section' => ParameterType::INTEGER],
        );

        $raw = [];
        foreach ($rows as $row) {
            $raw[$this->asString($row['name'])][$this->asInt($row['language_id'])] = $this->asString($row['content'] ?? '');
        }

        return $raw;
    }

    /**
     * The `list_key` of several list sections (a section that never saved one
     * reads the default key).
     *
     * @param list<int> $sectionIds
     * @return array<int, string> section id => key
     */
    public function listKeysOf(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT t.id_sections AS section_id, t.content AS content'
            . ' FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE f.name = :name AND t.id_languages = :language AND t.id_sections IN (:ids)',
            ['name' => 'list_key', 'language' => Language::INDEPENDENT_ID, 'ids' => $sectionIds],
            ['name' => ParameterType::STRING, 'language' => ParameterType::INTEGER, 'ids' => ArrayParameterType::INTEGER],
        );
        $stored = [];
        foreach ($rows as $row) {
            $content = trim($this->asString($row['content'] ?? ''));
            if ($content !== '') {
                $stored[$this->asInt($row['section_id'])] = $content;
            }
        }

        $keys = [];
        foreach ($sectionIds as $id) {
            $keys[$id] = $stored[$id] ?? FormListConfig::defaultsFor(StyleNames::STYLE_ENTRY_FORM_LIST)['list_key'];
        }

        return $keys;
    }

    /**
     * The validated configuration of a section as the visitor of a language sees it.
     *
     * @throws FormListConfigException when the stored configuration is not valid (500: a configuration fault)
     */
    public function read(int $sectionId, int $languageId, string $locale = 'en'): FormListConfig
    {
        $style = $this->styleOf($sectionId);
        if ($style === null || !in_array($style, StyleNames::LIST_STYLE_NAMES, true)) {
            throw new FormListConfigException(
                [['field' => 'style', 'message' => 'The section is not a list.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            return $this->parse($sectionId, $style, $this->rawFields($sectionId), $languageId, $locale);
        } catch (FormListConfigException $e) {
            throw new FormListConfigException($e->errors(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The `list_key` of several list sections of an effective page (a section that
     * never saved one reads the default key).
     *
     * @param list<int> $sectionIds
     * @return array<int, string> section id => key
     */
    public function listKeysIn(EffectivePage $page, array $sectionIds): array
    {
        $default = FormListConfig::defaultsFor(StyleNames::STYLE_ENTRY_FORM_LIST)['list_key'];
        $keys = [];
        foreach ($sectionIds as $id) {
            $stored = trim($page->rawFields($id)['list_key'][Language::INDEPENDENT_ID] ?? '');
            $keys[$id] = $stored !== '' ? $stored : $default;
        }

        return $keys;
    }

    /**
     * The validated configuration of a list section as the effective page holds it (the published
     * version for a visitor, the draft for an authorized preview), for the visitor of a language.
     *
     * @throws FormListConfigException when the section is not a list (400) or its stored configuration is not valid (500: a configuration fault)
     */
    public function readIn(EffectivePage $page, int $sectionId, int $languageId, string $locale = 'en'): FormListConfig
    {
        $style = $page->styleOf($sectionId);
        if ($style === null || !in_array($style, StyleNames::LIST_STYLE_NAMES, true)) {
            throw new FormListConfigException(
                [['field' => 'style', 'message' => 'The section is not a list.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            return $this->parse($sectionId, $style, $page->rawFields($sectionId), $languageId, $locale);
        } catch (FormListConfigException $e) {
            throw new FormListConfigException($e->errors(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Build the configuration from stored field content.
     *
     * @param array<string, array<int, string>> $raw field name => language id => content
     * @throws FormListConfigException with every problem found, status 400
     */
    public function parse(int $sectionId, string $style, array $raw, int $languageId, string $locale = 'en'): FormListConfig
    {
        $defaults = FormListConfig::defaultsFor($style);
        $errors = [];
        $isList = $style === StyleNames::STYLE_ENTRY_FORM_LIST;

        $value = static function (string $field, bool $translated = false) use ($raw, $defaults, $languageId): string {
            $stored = $raw[$field] ?? [];
            $content = $translated
                ? ($stored[$languageId] ?? $stored[Language::INDEPENDENT_ID] ?? null)
                : ($stored[Language::INDEPENDENT_ID] ?? ($stored === [] ? null : $stored[array_key_first($stored)]));

            return ($content === null || $content === '') ? ($defaults[$field] ?? '') : $content;
        };
        $flag = function (string $field) use ($value, &$errors): bool {
            $text = strtolower(trim($value($field)));
            if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($text, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
            $errors[] = ['field' => $field, 'message' => 'Use 1 or 0.'];

            return false;
        };

        $key = trim($value('list_key'));
        if (preg_match(FormListConfig::KEY_PATTERN, $key) !== 1) {
            $errors[] = ['field' => 'list_key', 'message' => 'The list key starts with a lowercase letter and has at most 32 lowercase letters, digits or underscores.'];
        }

        $tableText = trim($value('data_table'));
        $dataTableId = null;
        if ($tableText !== '') {
            if (ctype_digit($tableText) && (int) $tableText > 0) {
                $dataTableId = (int) $tableText;
            } else {
                $errors[] = ['field' => 'data_table', 'message' => 'The data table is a table id.'];
            }
        }
        if (!$isList && $dataTableId === null) {
            $errors[] = ['field' => 'data_table', 'message' => 'A data view needs a data table.'];
        }

        $ownEntriesOnly = $flag('own_entries_only');
        $allowCreate = $isList && $flag('allow_create');
        $allowUpdate = $isList && $flag('allow_update');
        $allowDelete = $isList && $flag('allow_delete');
        $showHistory = $isList && $flag('show_history');

        $layout = trim($value('list_layout'));
        if (!in_array($layout, FormListConfig::LAYOUTS, true)) {
            $errors[] = ['field' => 'list_layout', 'message' => 'The layout is "table", "cards" or "custom".'];
        } elseif ($isList && $layout === FormListConfig::LAYOUT_CUSTOM) {
            // The children of a list with a form are its form; only a data view takes sections as the layout of a record.
            $errors[] = ['field' => 'list_layout', 'message' => 'A list with a form shows its records as a "table" or as "cards"; the layout "custom" belongs to a data view.'];
        }

        $rowCss = $isList ? '' : trim($value('list_row_css'));
        if ($rowCss !== '' && (strlen($rowCss) > FormListConfig::MAX_ROW_CSS || preg_match(self::ROW_CSS_PATTERN, $rowCss) !== 1)) {
            $errors[] = ['field' => 'list_row_css', 'message' => sprintf('The record container classes are plain class names, at most %d characters.', FormListConfig::MAX_ROW_CSS)];
        }
        $presentation = $isList ? trim($value('form_presentation')) : FormListConfig::PRESENTATION_PAGE;
        if (!in_array($presentation, FormListConfig::PRESENTATIONS, true)) {
            $errors[] = ['field' => 'form_presentation', 'message' => 'The form is shown in a "modal" or on the "page".'];
        }

        $pageSizeText = trim($value('page_size'));
        $pageSize = ctype_digit($pageSizeText) ? (int) $pageSizeText : 0;
        if ($pageSize < 1 || $pageSize > 100) {
            $errors[] = ['field' => 'page_size', 'message' => 'The page size is between 1 and 100.'];
            $pageSize = 25;
        }

        $columns = $this->parseColumns($value('list_columns'), $locale, $errors);
        $sorts = $this->parseSorts($value('list_sort'), $errors);
        $controls = $this->parseControls($value('list_controls'), $locale, $errors);
        $fixedFilter = $this->parseFilter($value('list_fixed_filter'), 'list_fixed_filter', $errors);
        $quickActions = $isList ? $this->parseQuickActions($value('quick_actions'), $locale, $errors) : [];

        $detailUrl = null;
        if (!$isList) {
            $detail = trim($value('detail_url'));
            if ($detail !== '') {
                if (preg_match('~^/(?!/)[^\s]*$~', $detail) !== 1 || substr_count($detail, '{record_id}') !== 1) {
                    $errors[] = ['field' => 'detail_url', 'message' => 'The detail link is a path of this site and contains {record_id} once.'];
                } else {
                    $detailUrl = $detail;
                }
            }
        }

        if (!$ownEntriesOnly && $columns === [] && $errors === []) {
            $errors[] = ['field' => 'list_columns', 'message' => 'Choose the columns of a list that shows other people\'s records.'];
        }

        $labels = [];
        foreach (FormListConfig::LABEL_FIELDS as $labelKey => $field) {
            if (isset($defaults[$field])) {
                $labels[$labelKey] = $this->limit($value($field, true), 200);
            }
        }

        if ($errors !== []) {
            throw new FormListConfigException($errors);
        }

        return new FormListConfig(
            sectionId: $sectionId,
            style: $style,
            key: $key,
            dataTableId: $dataTableId,
            ownEntriesOnly: $ownEntriesOnly,
            allowCreate: $allowCreate,
            allowUpdate: $allowUpdate,
            allowDelete: $allowDelete,
            layout: $layout,
            formPresentation: $presentation,
            pageSize: $pageSize,
            columns: $columns,
            defaultSorts: $sorts,
            controls: $controls,
            fixedFilter: $fixedFilter,
            quickActions: $quickActions,
            showHistory: $showHistory,
            detailUrl: $detailUrl,
            labels: $labels,
            rowCss: $layout === FormListConfig::LAYOUT_CUSTOM ? $rowCss : '',
        );
    }

    // -- JSON fields ------------------------------------------------------------------------

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<array<array-key, mixed>>|null
     */
    private function decodeList(string $text, string $field, int $max, array &$errors): ?array
    {
        $decoded = $this->decode($text, $field, $errors);
        if ($decoded === null) {
            return null;
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            $errors[] = ['field' => $field, 'message' => 'Expected a list.'];

            return null;
        }
        if (count($decoded) > $max) {
            $errors[] = ['field' => $field, 'message' => sprintf('At most %d entries.', $max)];

            return null;
        }
        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                $errors[] = ['field' => $field, 'message' => 'Every entry is an object.'];

                return null;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function decode(string $text, string $field, array &$errors): mixed
    {
        if (strlen($text) > self::MAX_JSON_LENGTH) {
            $errors[] = ['field' => $field, 'message' => 'The value is too long.'];

            return null;
        }
        try {
            return json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors[] = ['field' => $field, 'message' => 'The value is not valid JSON.'];

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $item
     * @param list<string> $allowed
     * @param list<array{field: string, message: string}> $errors
     */
    private function onlyKeys(array $item, array $allowed, string $field, array &$errors): bool
    {
        foreach (array_keys($item) as $name) {
            if (!is_string($name) || !in_array($name, $allowed, true)) {
                $errors[] = ['field' => $field, 'message' => sprintf('Unknown property "%s".', (string) $name)];

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<FormListColumn>
     */
    private function parseColumns(string $text, string $locale, array &$errors): array
    {
        $items = $this->decodeList($text, 'list_columns', FormListConfig::MAX_COLUMNS, $errors);
        $columns = [];
        $seen = [];
        foreach ($items ?? [] as $item) {
            if (!$this->onlyKeys($item, self::COLUMN_KEYS, 'list_columns', $errors)) {
                return [];
            }
            $field = $item['field'] ?? null;
            if (!is_string($field) || preg_match(self::FIELD_PATTERN, $field) !== 1) {
                $errors[] = ['field' => 'list_columns', 'message' => 'Every column names a field.'];

                return [];
            }
            if (isset($seen[$field])) {
                $errors[] = ['field' => 'list_columns', 'message' => sprintf('The column "%s" is listed twice.', $field)];

                return [];
            }
            if (!$this->mayShowField($field)) {
                $errors[] = ['field' => 'list_columns', 'message' => sprintf('The column "%s" identifies a person and cannot be shown in a list.', $field)];

                return [];
            }
            $seen[$field] = true;
            $format = $item['format'] ?? FormListColumn::FORMAT_TEXT;
            if (!is_string($format) || !in_array($format, FormListColumn::FORMATS, true)) {
                $errors[] = ['field' => 'list_columns', 'message' => sprintf('Unknown format for the column "%s".', $field)];

                return [];
            }
            $columns[] = new FormListColumn(
                $field,
                $this->label($item['label'] ?? '', $locale, 'list_columns', $errors),
                $this->boolOf($item['sortable'] ?? false, 'list_columns', $errors),
                $this->boolOf($item['searchable'] ?? false, 'list_columns', $errors),
                $format,
            );
        }

        return $columns;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<DataSort>
     */
    private function parseSorts(string $text, array &$errors): array
    {
        $items = $this->decodeList($text, 'list_sort', 3, $errors);
        $sorts = [];
        foreach ($items ?? [] as $item) {
            if (!$this->onlyKeys($item, self::SORT_KEYS, 'list_sort', $errors)) {
                return [];
            }
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? DataSort::ASC;
            if (!is_string($field) || preg_match(self::FIELD_PATTERN, $field) !== 1
                || !in_array($direction, [DataSort::ASC, DataSort::DESC], true)) {
                $errors[] = ['field' => 'list_sort', 'message' => 'A sort names a field and a direction ("asc" or "desc").'];

                return [];
            }
            if (DataQueryEngine::isRowField($field) && !in_array($field, self::SORTABLE_ROW_FIELDS, true)) {
                $errors[] = ['field' => 'list_sort', 'message' => sprintf('A list cannot be ordered by "%s".', $field)];

                return [];
            }
            $sorts[] = new DataSort($field, (string) $direction);
        }

        return $sorts;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<FormListControl>
     */
    private function parseControls(string $text, string $locale, array &$errors): array
    {
        $items = $this->decodeList($text, 'list_controls', FormListConfig::MAX_CONTROLS, $errors);
        $controls = [];
        $seen = [];
        foreach ($items ?? [] as $item) {
            if (!$this->onlyKeys($item, self::CONTROL_KEYS, 'list_controls', $errors)) {
                return [];
            }
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? null;
            $field = $item['field'] ?? null;
            if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1
                || !is_string($type) || !in_array($type, FormListControl::TYPES, true)
                || !is_string($field) || preg_match(self::FIELD_PATTERN, $field) !== 1) {
                $errors[] = ['field' => 'list_controls', 'message' => 'A control has an id, a known type and a field.'];

                return [];
            }
            if (isset($seen[$id])) {
                $errors[] = ['field' => 'list_controls', 'message' => sprintf('The control "%s" is listed twice.', $id)];

                return [];
            }
            if (!$this->mayShowField($field)) {
                $errors[] = ['field' => 'list_controls', 'message' => sprintf('The control "%s" works on "%s", which identifies a person.', $id, $field)];

                return [];
            }
            $seen[$id] = true;

            $match = $item['match'] ?? 'contains';
            if (!is_string($match) || !in_array($match, FormListControl::MATCHES, true)) {
                $errors[] = ['field' => 'list_controls', 'message' => sprintf('Unknown match for the control "%s".', $id)];

                return [];
            }

            $options = null;
            $fromData = false;
            if (array_key_exists('options_from', $item)) {
                if ($item['options_from'] !== 'data') {
                    $errors[] = ['field' => 'list_controls', 'message' => 'options_from is "data".'];

                    return [];
                }
                $fromData = true;
            }
            if (array_key_exists('options', $item)) {
                $options = $this->parseOptions($item['options'], $locale, $errors);
                if ($options === null) {
                    return [];
                }
            }
            $control = new FormListControl($id, $type, $field, $this->label($item['label'] ?? '', $locale, 'list_controls', $errors), $options, $fromData, $match);
            if (!$control->usesChoices() && ($options !== null || $fromData)) {
                $errors[] = ['field' => 'list_controls', 'message' => sprintf('Only select controls have choices ("%s").', $id)];

                return [];
            }
            $controls[] = $control;
        }

        return $controls;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<array{value: string, label: string}>|null
     */
    private function parseOptions(mixed $raw, string $locale, array &$errors): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > FormListConfig::MAX_CHOICES) {
            $errors[] = ['field' => 'list_controls', 'message' => sprintf('Choices are a list of at most %d entries.', FormListConfig::MAX_CHOICES)];

            return null;
        }
        $options = [];
        foreach ($raw as $option) {
            if (!is_array($option) || !is_scalar($option['value'] ?? null) || !$this->onlyKeys($option, ['value', 'label'], 'list_controls', $errors)) {
                $errors[] = ['field' => 'list_controls', 'message' => 'A choice has a value and an optional label.'];

                return null;
            }
            $optionValue = (string) $option['value'];
            if ($optionValue === '' || strlen($optionValue) > 500) {
                $errors[] = ['field' => 'list_controls', 'message' => 'A choice value has 1 to 500 characters.'];

                return null;
            }
            $label = $this->label($option['label'] ?? '', $locale, 'list_controls', $errors);
            $options[] = ['value' => $optionValue, 'label' => $label === '' ? $optionValue : $label];
        }

        return $options;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     * @return list<FormListQuickAction>
     */
    private function parseQuickActions(string $text, string $locale, array &$errors): array
    {
        $items = $this->decodeList($text, 'quick_actions', FormListConfig::MAX_QUICK_ACTIONS, $errors);
        $actions = [];
        $seen = [];
        foreach ($items ?? [] as $item) {
            if (!$this->onlyKeys($item, self::ACTION_KEYS, 'quick_actions', $errors)) {
                return [];
            }
            $id = $item['id'] ?? null;
            if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                $errors[] = ['field' => 'quick_actions', 'message' => 'A quick action has an id.'];

                return [];
            }
            if (isset($seen[$id])) {
                $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" is listed twice.', $id)];

                return [];
            }
            $seen[$id] = true;

            $label = $this->label($item['label'] ?? '', $locale, 'quick_actions', $errors);
            if ($label === '') {
                $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" needs a label.', $id)];

                return [];
            }
            $confirm = null;
            if (isset($item['confirm']) && $item['confirm'] !== '') {
                $confirm = $this->limit($this->label($item['confirm'], $locale, 'quick_actions', $errors), self::MAX_CONFIRM_LENGTH);
            }

            $set = $item['set'] ?? null;
            if (!is_array($set) || $set === [] || array_is_list($set) || count($set) > self::MAX_ASSIGNED_FIELDS) {
                $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" assigns 1 to %d fields.', $id, self::MAX_ASSIGNED_FIELDS)];

                return [];
            }
            $assigned = [];
            foreach ($set as $column => $assignedValue) {
                if (DataQueryEngine::isRowField((string) $column)) {
                    $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" can only assign columns of the form.', $id)];

                    return [];
                }
                if (preg_match(self::FIELD_PATTERN, (string) $column) !== 1
                    || !(is_string($assignedValue) || is_int($assignedValue) || is_float($assignedValue) || is_bool($assignedValue))
                    || (is_string($assignedValue) && strlen($assignedValue) > 500)) {
                    $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The quick action "%s" assigns text, number or yes/no values to columns.', $id)];

                    return [];
                }
                $assigned[(string) $column] = $assignedValue;
            }

            $when = new DataFilterGroup();
            if (isset($item['when'])) {
                try {
                    $when = DataFilterJson::parse($item['when']);
                } catch (DataQueryException $e) {
                    $errors[] = ['field' => 'quick_actions', 'message' => sprintf('The condition of "%s": %s', $id, $e->getMessage())];

                    return [];
                }
                if (DataFilterJson::depth($when) > self::MAX_WHEN_DEPTH || DataFilterJson::nodes($when) > self::MAX_WHEN_NODES) {
                    $errors[] = ['field' => 'quick_actions', 'message' => sprintf(
                        'The condition of "%s" has at most %d conditions and %d levels.',
                        $id,
                        self::MAX_WHEN_NODES,
                        self::MAX_WHEN_DEPTH,
                    )];

                    return [];
                }
            }
            $actions[] = new FormListQuickAction($id, $label, $confirm, $assigned, $when);
        }

        return $actions;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function parseFilter(string $text, string $field, array &$errors): DataFilterGroup
    {
        $decoded = $this->decode($text, $field, $errors);
        if ($decoded === null) {
            return new DataFilterGroup();
        }
        try {
            $group = DataFilterJson::parse($decoded);
        } catch (DataQueryException $e) {
            $errors[] = ['field' => $field, 'message' => $e->getMessage()];

            return new DataFilterGroup();
        }

        // The visitor's choices and the record being opened are added to the
        // author's filter; the engine's limits must have room for them. An "and"
        // filter shares its top level with them, an "or" filter is nested.
        $levels = DataFilterJson::depth($group) + ($group->mode === DataFilterGroup::MODE_OR && !$group->isEmpty() ? 1 : 0);
        if ($levels > DataQueryLimits::MAX_FILTER_DEPTH || DataFilterJson::nodes($group) > self::MAX_FIXED_FILTER_NODES) {
            $errors[] = ['field' => $field, 'message' => sprintf(
                'The fixed filter has at most %d conditions and %d levels.',
                self::MAX_FIXED_FILTER_NODES,
                DataQueryLimits::MAX_FILTER_DEPTH,
            )];

            return new DataFilterGroup();
        }

        return $group;
    }

    // -- small values ------------------------------------------------------------------------

    /** A column of the table or the date of an entry; never a field that names a person. */
    private function mayShowField(string $field): bool
    {
        return !DataQueryEngine::isRowField($field) || in_array($field, self::VISIBLE_ROW_FIELDS, true);
    }

    /**
     * A text that is either one string or a map from language code to string.
     *
     * @param list<array{field: string, message: string}> $errors
     */
    private function label(mixed $raw, string $locale, string $field, array &$errors): string
    {
        if (is_string($raw)) {
            return $this->limit($raw, self::MAX_LABEL_LENGTH);
        }
        if (is_array($raw) && $raw !== [] && !array_is_list($raw)) {
            $language = strtolower((string) preg_replace('/[-_].*$/', '', $locale));
            $candidates = [$locale, strtolower($locale), $language, 'en'];
            foreach ($candidates as $candidate) {
                if (isset($raw[$candidate]) && is_string($raw[$candidate])) {
                    return $this->limit($raw[$candidate], self::MAX_LABEL_LENGTH);
                }
            }
            $first = reset($raw);

            return is_string($first) ? $this->limit($first, self::MAX_LABEL_LENGTH) : '';
        }
        if ($raw !== null && $raw !== []) {
            $errors[] = ['field' => $field, 'message' => 'A label is a text or a text per language.'];
        }

        return '';
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function boolOf(mixed $raw, string $field, array &$errors): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $errors[] = ['field' => $field, 'message' => 'Switches are true or false.'];

        return false;
    }

    private function limit(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) : $text;
    }
}
