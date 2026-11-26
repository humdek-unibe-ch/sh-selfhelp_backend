<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\DataTable;
use App\Entity\Language;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataQuery\LegacyFilterTranslator;
use App\Service\CMS\FormFieldKeyResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads of the generic data store in the shape the older callers use: a filter
 * fragment, a user id, a language and a column list. Every read runs through
 * the {@see DataQueryEngine}; the filter fragment is turned into a structured
 * filter by {@see LegacyFilterTranslator} and never reaches SQL as text.
 *
 * New code builds a {@see DataQuery} and calls the engine directly.
 *
 * @extends ServiceEntityRepository<DataTable>
 */
class DataTableRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DataTableFilterService $dataTableFilterService,
        private readonly DataQueryEngine $dataQueryEngine,
        private readonly LegacyFilterTranslator $legacyFilterTranslator,
        private readonly FormFieldKeyResolver $formFieldKeyResolver,
    ) {
        parent::__construct($registry, DataTable::class);
    }

    /**
     * Rows of a table, restricted to the records of one user when `$userId` is
     * above zero and unrestricted otherwise. A caller that must never read other
     * users' records passes the owner scope through {@see DataQuery} instead of a
     * magic user id.
     *
     * @param string $selectedColumns comma-separated column keys; empty reads every column
     * @return list<array<string, mixed>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function getDataTableWithFilter(
        int $tableId,
        int $userId,
        string $filter,
        bool $excludeDeleted,
        int $languageId = Language::INDEPENDENT_ID,
        string $timezoneCode = 'UTC',
        string $selectedColumns = '',
    ): array {
        return $this->read(
            $tableId,
            $userId > 0 ? DataQuery::OWNER_USER : DataQuery::OWNER_ANY,
            $userId > 0 ? $userId : null,
            $filter,
            $excludeDeleted,
            $languageId,
            $timezoneCode,
            $selectedColumns,
            false,
        );
    }

    /**
     * Rows written by the users of the groups that the current user's roles may
     * access. A user with no accessible group reads nothing.
     *
     * @return list<array<string, mixed>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function getDataTableWithUserGroupFilter(int $tableId, int $currentUserId, string $filter, bool $excludeDeleted, int $languageId = Language::INDEPENDENT_ID, string $timezoneCode = 'UTC'): array
    {
        return $this->read($tableId, DataQuery::OWNER_GROUPS, $currentUserId, $filter, $excludeDeleted, $languageId, $timezoneCode, '', false);
    }

    /**
     * One row per record and language, with no language fallback.
     *
     * @return list<array<string, mixed>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function getDataTableWithAllLanguages(int $tableId, int $userId, string $filter, bool $excludeDeleted, string $timezoneCode = 'UTC'): array
    {
        return $this->read(
            $tableId,
            $userId > 0 ? DataQuery::OWNER_USER : DataQuery::OWNER_ANY,
            $userId > 0 ? $userId : null,
            $filter,
            $excludeDeleted,
            1,
            $timezoneCode,
            '',
            true,
        );
    }

    /**
     * The rows {@see getDataTableWithFilter()} returns, in stable batches instead
     * of one list: exports and background work read a table of any size without
     * holding it in memory. The records, their order and their shape are the
     * same as the single read.
     *
     * @param string $selectedColumns comma-separated column keys; empty reads every column
     * @return \Generator<int, list<array<string, mixed>>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function batchesWithFilter(
        int $tableId,
        int $userId,
        string $filter,
        bool $excludeDeleted,
        int $languageId = Language::INDEPENDENT_ID,
        string $timezoneCode = 'UTC',
        string $selectedColumns = '',
        int $batchSize = 500,
    ): \Generator {
        $query = $this->queryFor(
            $tableId,
            $userId > 0 ? DataQuery::OWNER_USER : DataQuery::OWNER_ANY,
            $userId > 0 ? $userId : null,
            $filter,
            $excludeDeleted,
            $languageId,
            $timezoneCode,
            $selectedColumns,
            false,
        );
        if ($query === null) {
            return;
        }

        yield from $this->dataQueryEngine->batches($query, $batchSize);
    }

    /**
     * The rows {@see getDataTableWithAllLanguages()} returns, in stable batches: one
     * row per record and language, with no language fallback, so a translated
     * cell is read as it was written. A batch holds the rows of whole records.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function batchesWithAllLanguages(int $tableId, int $userId, string $filter, bool $excludeDeleted, string $timezoneCode = 'UTC', int $batchSize = 500): \Generator
    {
        $query = $this->queryFor(
            $tableId,
            $userId > 0 ? DataQuery::OWNER_USER : DataQuery::OWNER_ANY,
            $userId > 0 ? $userId : null,
            $filter,
            $excludeDeleted,
            1,
            $timezoneCode,
            '',
            true,
        );
        if ($query === null) {
            return;
        }

        yield from $this->dataQueryEngine->batches($query, $batchSize);
    }

    /**
     * The rows {@see getDataTableWithUserGroupFilter()} returns, in stable batches.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     * @throws \App\Service\CMS\DataQuery\DataQueryException when the filter cannot be read as a structured filter
     */
    public function batchesWithUserGroupFilter(int $tableId, int $currentUserId, string $filter, bool $excludeDeleted, int $languageId = Language::INDEPENDENT_ID, string $timezoneCode = 'UTC', int $batchSize = 500): \Generator
    {
        $query = $this->queryFor($tableId, DataQuery::OWNER_GROUPS, $currentUserId, $filter, $excludeDeleted, $languageId, $timezoneCode, '', false);
        if ($query === null) {
            return;
        }

        yield from $this->dataQueryEngine->batches($query, $batchSize);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(
        int $tableId,
        string $ownerScope,
        ?int $userId,
        string $filter,
        bool $excludeDeleted,
        int $languageId,
        string $timezoneCode,
        string $selectedColumns,
        bool $allLanguages,
    ): array {
        $query = $this->queryFor($tableId, $ownerScope, $userId, $filter, $excludeDeleted, $languageId, $timezoneCode, $selectedColumns, $allLanguages);

        return $query === null ? [] : $this->dataQueryEngine->run($query)->rows;
    }

    /**
     * The structured query of a legacy read, or null when the read can only be empty
     * (a column selection that matches no column of the table).
     */
    private function queryFor(
        int $tableId,
        string $ownerScope,
        ?int $userId,
        string $filter,
        bool $excludeDeleted,
        int $languageId,
        string $timezoneCode,
        string $selectedColumns,
        bool $allLanguages,
    ): ?DataQuery {
        $translated = $this->legacyFilterTranslator->translate($this->dataTableFilterService->guardForStoredProcedure($filter));
        // Authors write input names (`status`); the engine needs field keys
        // (`section_123`). Same rewrite FormList / fixed_filter already apply.
        $resolvedFilter = $this->resolveInputNamesInFilter($tableId, $translated->filter);
        $resolvedSorts = $this->resolveInputNamesInSorts($tableId, $translated->sorts);

        $columns = null;
        $selected = $this->dataTableFilterService->sanitizeSelectedColumns($selectedColumns);
        if ($selected !== '') {
            // A selection keeps the columns the table has; one that matches none reads nothing.
            $nameToKey = $this->nameToFieldKey($tableId);
            $requested = array_map(
                static fn (string $name): string => $nameToKey[$name] ?? $name,
                explode(',', $selected),
            );
            $columns = array_values(array_intersect($requested, array_keys($this->dataQueryEngine->columnsOf($tableId))));
            if ($columns === []) {
                return null;
            }
        }

        return new DataQuery(
            tableId: $tableId,
            ownerScope: $ownerScope,
            userId: $userId,
            filter: $resolvedFilter,
            sorts: $resolvedSorts,
            limit: $translated->limit,
            offset: $translated->offset,
            columns: $columns,
            excludeDeleted: $excludeDeleted,
            languageId: $languageId,
            allLanguages: $allLanguages,
            timezone: $timezoneCode,
        );
    }

    /**
     * @return array<string, string> input name => field_key
     */
    private function nameToFieldKey(int $tableId): array
    {
        $table = $this->find($tableId);
        $tableName = $table !== null ? (string) $table->getName() : '';
        if ($tableName === '') {
            return [];
        }

        return $this->formFieldKeyResolver->getNameToFieldKey($tableName);
    }

    private function resolveInputNamesInFilter(int $tableId, DataFilterGroup $group): DataFilterGroup
    {
        if ($group->isEmpty()) {
            return $group;
        }
        $nameToKey = $this->nameToFieldKey($tableId);
        if ($nameToKey === []) {
            return $group;
        }

        return $this->mapFilterGroup($group, $nameToKey);
    }

    /**
     * @param list<DataSort> $sorts
     * @return list<DataSort>
     */
    private function resolveInputNamesInSorts(int $tableId, array $sorts): array
    {
        if ($sorts === []) {
            return $sorts;
        }
        $nameToKey = $this->nameToFieldKey($tableId);
        if ($nameToKey === []) {
            return $sorts;
        }
        $resolved = [];
        foreach ($sorts as $sort) {
            $resolved[] = new DataSort($nameToKey[$sort->column] ?? $sort->column, $sort->direction);
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $nameToKey
     */
    private function mapFilterGroup(DataFilterGroup $group, array $nameToKey): DataFilterGroup
    {
        $children = [];
        foreach ($group->children as $child) {
            if ($child instanceof DataFilterGroup) {
                $children[] = $this->mapFilterGroup($child, $nameToKey);
            } elseif ($child instanceof DataFilter) {
                $children[] = new DataFilter(
                    $nameToKey[$child->column] ?? $child->column,
                    $child->operator,
                    $child->value,
                    $child->valueType,
                );
            }
        }

        return new DataFilterGroup($group->mode, $children);
    }

    /**
     * Get data table id by name
     *
     * @param string $name Data table name
     * @return int Data table id
     */
    public function getDataTableIdByName(string $name): int
    {
        $dataTable = $this->findOneBy(['name' => $name]);
        assert($dataTable !== null);
        $id = $dataTable->getId();
        assert($id !== null);

        return $id;
    }
}
