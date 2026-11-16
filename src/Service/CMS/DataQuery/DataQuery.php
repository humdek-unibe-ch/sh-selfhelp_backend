<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * A structured read of one generic data table, executed by
 * {@see DataQueryEngine}. Every reader of the generic store (lists, tables,
 * repeaters, prefills, exports, admin screens) describes its read with this
 * object, so access, typing, language fallback and paging behave the same
 * everywhere.
 *
 * The engine selects the candidate record ids first, pages them, and only then
 * reads the cells of that page. The cells it reads are limited to the
 * projected columns.
 */
final readonly class DataQuery
{
    /** No owner restriction: admin and global readers. */
    public const OWNER_ANY = 'any';

    /** Only the records of `$userId`. Without a signed-in user (no id) nothing matches, so a guest never reads anonymous records. */
    public const OWNER_USER = 'user';

    /** Only the records of users in the groups that the roles of `$userId` may access. */
    public const OWNER_GROUPS = 'groups';

    /**
     * @param int $tableId the generic table to read
     * @param string $ownerScope one of the OWNER_* constants
     * @param int|null $userId the user for OWNER_USER and OWNER_GROUPS
     * @param DataFilterGroup $filter the fixed dataset boundary and any visitor filters, combined by the group
     * @param DataSearch|null $search visitor text search; narrows the filtered set
     * @param list<DataSort> $sorts sort keys in priority order; the record id breaks ties
     * @param int|null $limit page size; null reads every matching record (legacy complete reads, a caller must choose this on purpose)
     * @param int $offset records to skip
     * @param list<string>|null $columns projected column keys; null reads every column of the table
     * @param bool $excludeDeleted leave out soft-deleted records
     * @param int $languageId requested language; a missing cell falls back to the default language (id 1)
     * @param bool $allLanguages one result row per record and language instead of one per record
     * @param string $timezone time zone code that `entry_date` is shown in
     * @param bool $withTotal also count the matching records
     * @param array<string, string> $optionalColumns columns the table may not have yet (an input of its form that nobody
     *                                              submitted so far), by key with the value type; they read as empty
     *                                              instead of being refused as unknown
     */
    public function __construct(
        public int $tableId,
        public string $ownerScope = self::OWNER_ANY,
        public ?int $userId = null,
        public DataFilterGroup $filter = new DataFilterGroup(),
        public ?DataSearch $search = null,
        public array $sorts = [],
        public ?int $limit = DataQueryLimits::DEFAULT_PAGE_SIZE,
        public int $offset = 0,
        public ?array $columns = null,
        public bool $excludeDeleted = true,
        public int $languageId = 1,
        public bool $allLanguages = false,
        public string $timezone = 'UTC',
        public bool $withTotal = false,
        public array $optionalColumns = [],
    ) {
    }

    /**
     * The same query narrowed to one record of the table.
     */
    public function forRecord(int $recordId): self
    {
        return new self(
            tableId: $this->tableId,
            ownerScope: $this->ownerScope,
            userId: $this->userId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [
                // An "and" filter is merged, not wrapped: same meaning, one level less.
                ...($this->filter->mode === DataFilterGroup::MODE_AND ? $this->filter->children : [$this->filter]),
                new DataFilter('record_id', DataFilter::OP_EQ, $recordId),
            ]),
            search: null,
            sorts: [],
            limit: 1,
            offset: 0,
            columns: $this->columns,
            excludeDeleted: $this->excludeDeleted,
            languageId: $this->languageId,
            allLanguages: $this->allLanguages,
            timezone: $this->timezone,
            withTotal: false,
            optionalColumns: $this->optionalColumns,
        );
    }

    /**
     * The same query with another page window.
     */
    public function withPage(?int $limit, int $offset): self
    {
        return new self(
            tableId: $this->tableId,
            ownerScope: $this->ownerScope,
            userId: $this->userId,
            filter: $this->filter,
            search: $this->search,
            sorts: $this->sorts,
            limit: $limit,
            offset: $offset,
            columns: $this->columns,
            excludeDeleted: $this->excludeDeleted,
            languageId: $this->languageId,
            allLanguages: $this->allLanguages,
            timezone: $this->timezone,
            withTotal: $this->withTotal,
            optionalColumns: $this->optionalColumns,
        );
    }
}
