<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataSort;

/**
 * The validated configuration of one `entry-form-list` or `data-view` section.
 * Built by {@see FormListConfigReader}; nothing else reads the section fields
 * of these styles.
 */
final readonly class FormListConfig
{
    public const LAYOUT_TABLE = 'table';
    public const LAYOUT_CARDS = 'cards';
    /** The sections inside a data view are the layout of one record (see {@see FormListRowTemplateRenderer}). */
    public const LAYOUT_CUSTOM = 'custom';
    public const LAYOUTS = [self::LAYOUT_TABLE, self::LAYOUT_CARDS, self::LAYOUT_CUSTOM];

    /** Longest value of `list_row_css`. */
    public const MAX_ROW_CSS = 300;

    public const PRESENTATION_MODAL = 'modal';
    public const PRESENTATION_PAGE = 'page';
    public const PRESENTATIONS = [self::PRESENTATION_MODAL, self::PRESENTATION_PAGE];

    /** Name of a list in the URL: `<key>.page`, `<key>.q`, ... */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public const MAX_COLUMNS = 30;
    public const MAX_CONTROLS = 12;
    public const MAX_QUICK_ACTIONS = 8;
    public const MAX_CHOICES = 50;

    /** The label fields of the styles; their values are translatable. */
    public const LABEL_FIELDS = [
        'add' => 'label_add',
        'empty' => 'label_empty',
        'search' => 'label_search',
        'confirm_delete' => 'label_confirm_delete',
    ];

    private const COMMON_DEFAULTS = [
        'list_key' => 'list',
        'data_table' => '',
        'list_layout' => self::LAYOUT_TABLE,
        'page_size' => '25',
        'list_columns' => '[]',
        'list_sort' => '[{"field":"entry_date","direction":"desc"}]',
        'list_controls' => '[]',
        'list_fixed_filter' => '{"mode":"and","children":[]}',
        'label_empty' => 'No entries yet.',
        'label_search' => 'Search',
    ];

    private const LIST_DEFAULTS = [
        'own_entries_only' => '1',
        'allow_create' => '1',
        'allow_update' => '1',
        'allow_delete' => '1',
        'form_presentation' => self::PRESENTATION_MODAL,
        'quick_actions' => '[]',
        'show_history' => '0',
        'label_add' => 'Add entry',
        'label_confirm_delete' => 'Delete this entry?',
    ];

    private const VIEW_DEFAULTS = [
        'own_entries_only' => '0',
        'detail_url' => '',
        'list_row_css' => '',
        'label_empty' => 'Nothing to show yet.',
    ];

    /**
     * @param int $sectionId the list's section
     * @param string $style `entry-form-list` or `data-view`
     * @param string $key the list's name in the URL
     * @param int|null $dataTableId the table the list reads; null = the table of the inline form
     * @param bool $ownEntriesOnly the list shows only the signed-in user's records
     * @param list<FormListColumn> $columns the visible columns; empty = every column of the table
     * @param list<DataSort> $defaultSorts the order before the visitor chooses one
     * @param list<FormListControl> $controls
     * @param DataFilterGroup $fixedFilter the author's boundary of the dataset
     * @param list<FormListQuickAction> $quickActions
     * @param string|null $detailUrl a data-view's link per record, with the `{record_id}` placeholder
     * @param array<string, string> $labels keyed by {@see self::LABEL_FIELDS} keys, in the visitor's language
     * @param string $rowCss the classes of the element that holds the records of a custom layout (web only)
     */
    public function __construct(
        public int $sectionId,
        public string $style,
        public string $key,
        public ?int $dataTableId,
        public bool $ownEntriesOnly,
        public bool $allowCreate,
        public bool $allowUpdate,
        public bool $allowDelete,
        public string $layout,
        public string $formPresentation,
        public int $pageSize,
        public array $columns,
        public array $defaultSorts,
        public array $controls,
        public DataFilterGroup $fixedFilter,
        public array $quickActions,
        public bool $showHistory,
        public ?string $detailUrl,
        public array $labels,
        public string $rowCss = '',
    ) {
    }

    /**
     * The stored default of every field of a style: what the seed migration
     * writes into `styles_fields.default_value`. A section that never saved the
     * field reads this value.
     *
     * @return array<string, string>
     */
    public static function defaultsFor(string $style): array
    {
        // The style's own default wins over the common one (`+` keeps the left value of a key).
        return $style === StyleNames::STYLE_DATA_VIEW
            ? self::VIEW_DEFAULTS + self::COMMON_DEFAULTS
            : self::LIST_DEFAULTS + self::COMMON_DEFAULTS;
    }

    /**
     * The same configuration with other columns: a list that names none shows
     * the columns of its table.
     *
     * @param list<FormListColumn> $columns
     */
    public function withColumns(array $columns): self
    {
        return new self(
            $this->sectionId,
            $this->style,
            $this->key,
            $this->dataTableId,
            $this->ownEntriesOnly,
            $this->allowCreate,
            $this->allowUpdate,
            $this->allowDelete,
            $this->layout,
            $this->formPresentation,
            $this->pageSize,
            $columns,
            $this->defaultSorts,
            $this->controls,
            $this->fixedFilter,
            $this->quickActions,
            $this->showHistory,
            $this->detailUrl,
            $this->labels,
            $this->rowCss,
        );
    }

    /** A data view whose records are drawn from the sections placed inside it. */
    public function usesRowTemplate(): bool
    {
        return $this->style === StyleNames::STYLE_DATA_VIEW && $this->layout === self::LAYOUT_CUSTOM;
    }

    /** A list that offers create, edit and delete; a data view only reads. */
    public function isEditable(): bool
    {
        return $this->style === StyleNames::STYLE_ENTRY_FORM_LIST;
    }

    public function column(string $field): ?FormListColumn
    {
        foreach ($this->columns as $column) {
            if ($column->field === $field) {
                return $column;
            }
        }

        return null;
    }

    public function control(string $id): ?FormListControl
    {
        foreach ($this->controls as $control) {
            if ($control->id === $id) {
                return $control;
            }
        }

        return null;
    }

    public function quickAction(string $id): ?FormListQuickAction
    {
        foreach ($this->quickActions as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Columns the visitor's text search looks into.
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        $fields = [];
        foreach ($this->columns as $column) {
            if ($column->searchable) {
                $fields[] = $column->field;
            }
        }

        return $fields;
    }

    /**
     * Columns the visitor may sort by.
     *
     * @return list<string>
     */
    public function sortableFields(): array
    {
        $fields = [];
        foreach ($this->columns as $column) {
            if ($column->sortable) {
                $fields[] = $column->field;
            }
        }

        return $fields;
    }

    /**
     * The fields the list's rows project: the visible columns, the columns the
     * controls and quick actions look at are filtered by the engine and need
     * no projection.
     *
     * @return list<string>|null null = every column of the table
     */
    public function projectedFields(): ?array
    {
        if ($this->columns === []) {
            return null;
        }

        $fields = [];
        foreach ($this->columns as $column) {
            if (!DataQueryEngine::isRowField($column->field)) {
                $fields[] = $column->field;
            }
        }

        return $fields;
    }
}
