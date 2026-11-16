<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\DataQuery\DataSort;

/**
 * What a visitor changed in a list, as it travels in the page URL:
 *
 *   <key>.page=2  <key>.q=anna  <key>.sort=section_3:asc,entry_date:desc
 *   <key>.f.<control>=value  <key>.record=17  <key>.mode=view|edit|create|history
 *
 * The state names choices only. It never carries a table, a column filter, an
 * operation or a permission: those come from the stored configuration.
 */
final readonly class FormListUrlState
{
    public const MODE_VIEW = 'view';
    public const MODE_EDIT = 'edit';
    public const MODE_CREATE = 'create';
    /** Open a record on the history tab (same record load as view). */
    public const MODE_HISTORY = 'history';
    public const MODES = [self::MODE_VIEW, self::MODE_EDIT, self::MODE_CREATE, self::MODE_HISTORY];

    /**
     * @param int $page 1-based page number
     * @param string|null $search the visitor's search text, trimmed
     * @param list<DataSort> $sorts the visitor's sort keys; empty = the list's default order
     * @param array<string, string> $filters control id => the control's raw value
     * @param int|null $recordId the record that is open
     * @param string|null $mode `view`, `edit`, `create` or `history`; null = the list only
     */
    public function __construct(
        public int $page = 1,
        public ?string $search = null,
        public array $sorts = [],
        public array $filters = [],
        public ?int $recordId = null,
        public ?string $mode = null,
    ) {
    }

    /** The same state on another page. */
    public function onPage(int $page): self
    {
        return new self($page, $this->search, $this->sorts, $this->filters, $this->recordId, $this->mode);
    }

    /**
     * The state as the URL grammar writes it, for echoing in responses.
     *
     * @return array<string, string>
     */
    public function toQuery(string $key): array
    {
        $query = [];
        if ($this->page > 1) {
            $query[$key . '.page'] = (string) $this->page;
        }
        if ($this->search !== null) {
            $query[$key . '.q'] = $this->search;
        }
        if ($this->sorts !== []) {
            $query[$key . '.sort'] = implode(',', array_map(
                static fn (DataSort $sort): string => $sort->column . ':' . $sort->direction,
                $this->sorts,
            ));
        }
        foreach ($this->filters as $id => $value) {
            $query[$key . '.f.' . $id] = $value;
        }
        if ($this->recordId !== null) {
            $query[$key . '.record'] = (string) $this->recordId;
        }
        if ($this->mode !== null) {
            $query[$key . '.mode'] = $this->mode;
        }

        return $query;
    }
}
