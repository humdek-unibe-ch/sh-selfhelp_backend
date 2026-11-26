<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

use App\Entity\Language;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one reader of the generic data store (`data_tables`, `data_cols`,
 * `data_rows`, `data_cells`).
 *
 * How a read runs:
 *  1. The columns of the table are read (key, id, declared value type).
 *  2. One query selects the candidate record ids: the table, the owner scope,
 *     the deleted rows, the filters, the search and the sort. Each column that
 *     a filter, search or sort uses is reached by a primary-key lookup of its
 *     cell; no pivot over the table is built.
 *  3. The page of ids is cut (LIMIT/OFFSET) before any cell is read.
 *  4. The cells of that page, for the projected columns only, are read and
 *     placed into rows.
 *
 * Semantics (frozen, tested in FormListQueryTest):
 *  - Language: a cell in the requested language wins; a missing one falls back
 *    to the language-independent cell ({@see Language::INDEPENDENT_ID}), then
 *    to the CMS default language when that is set and differs. The legacy
 *    procedures returned the larger of the two texts when both existed; that
 *    is corrected here.
 *  - Types: comparisons and sorting read the text of a cell as the column's
 *    declared type (text, number, date, datetime, boolean). A value that cannot
 *    be read as that type is treated like a missing one and sorts last.
 *  - Missing, empty and null: see {@see DataFilter}.
 *  - Order: the requested sort keys, then the record id (same direction as the
 *    first key). Without a sort the order is the record id ascending.
 *  - Owner scope: OWNER_USER with no user matches nothing.
 *  - Deleted: soft-deleted records (trigger type `deleted`) are left out unless
 *    the query keeps them.
 *
 * Results are cached per table: any write to the table bumps the table scope,
 * so a cached page never outlives a change of its data. The CMS default
 * language id is part of every cache key, so a preference change cannot serve
 * a stale fallback. Queries that depend on group grants are not cached.
 */
class DataQueryEngine extends BaseService
{
    /** Part of every cache key; raise it when the shape of a cached result changes. */
    private const ENGINE_VERSION = 3; // 3: L → independent → CMS-default cell fallback

    /** Row fields a filter or sort may name besides the table's columns. */
    private const ROW_FIELDS = [
        'record_id' => ['expr' => 'r.id', 'type' => 'number'],
        'id_users' => ['expr' => 'r.id_users', 'type' => 'number'],
        'id_action_trigger_types' => ['expr' => 'r.id_action_trigger_types', 'type' => 'number'],
        'entry_date' => ['expr' => 'r.`timestamp`', 'type' => 'datetime'],
        'user_name' => ['expr' => '(SELECT u.`name` FROM users u WHERE u.id = r.id_users)', 'type' => 'text'],
        'user_code' => ['expr' => '(SELECT MIN(vc.code) FROM validation_codes vc WHERE vc.id_users = r.id_users)', 'type' => 'text'],
        'triggerType' => ['expr' => '(SELECT l.lookup_code FROM lookups l WHERE l.id = r.id_action_trigger_types)', 'type' => 'text'],
    ];

    private const LIKE_ESCAPE = " ESCAPE '\\\\'";

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly CmsPreferenceService $cmsPreferenceService,
    ) {
    }

    /**
     * Run a query and return its rows (and the total when asked for).
     */
    public function run(DataQuery $query): DataQueryResult
    {
        if ($query->ownerScope === DataQuery::OWNER_GROUPS) {
            return $this->execute($query);
        }

        $key = 'dq_' . self::ENGINE_VERSION . '_' . sha1(serialize($query) . $this->cacheKeyDefaultSuffix());
        /** @var array{rows: list<array<string, mixed>>, total: int|null} $cached */
        $cached = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $query->tableId)
            ->getList($key, function () use ($query): array {
                $result = $this->execute($query);

                return ['rows' => $result->rows, 'total' => $result->total];
            });

        return new DataQueryResult($cached['rows'], $cached['total']);
    }

    /**
     * Number of records the query matches, ignoring its page window.
     */
    public function count(DataQuery $query): int
    {
        $columns = $this->columnsFor($query);
        $plan = $this->plan($query, $columns, forCount: true);
        $sql = 'SELECT COUNT(*) FROM data_rows r' . $plan->joinSql() . ' WHERE ' . $plan->whereSql();

        return $this->asInt($this->connection()->fetchOne($sql, $plan->params, $plan->types));
    }

    /**
     * The values one column holds in the records the query matches, most
     * frequent first, with the number of records that hold each. Empty values
     * are left out. Filters, search and the owner scope of the query apply, so
     * a visitor is only ever offered values of records the visitor may see.
     *
     * @return list<array{value: string, count: int}>
     */
    public function facet(DataQuery $query, string $column, int $limit = 50): array
    {
        $limit = max(1, min($limit, DataQueryLimits::MAX_LIST_VALUES));
        $key = 'dqf_' . self::ENGINE_VERSION . '_' . sha1(serialize([$query, $column, $limit]) . $this->cacheKeyDefaultSuffix());
        $compute = function () use ($query, $column, $limit): array {
            $columns = $this->columnsFor($query);
            if (!isset($columns[$column])) {
                throw new DataQueryException(sprintf('Unknown column "%s".', $column));
            }
            $plan = $this->plan($query, $columns, forCount: true);
            $expression = $this->cellText($plan, $columns[$column]['id']);
            $sql = 'SELECT ' . $expression . ' AS facet_value, COUNT(DISTINCT r.id) AS facet_count FROM data_rows r'
                . $plan->joinSql() . ' WHERE ' . $plan->whereSql() . ' AND ' . $expression . " <> ''"
                . ' GROUP BY facet_value ORDER BY facet_count DESC, facet_value ASC LIMIT ' . $limit;

            $values = [];
            foreach ($this->connection()->fetchAllAssociative($sql, $plan->params, $plan->types) as $row) {
                $values[] = ['value' => $this->asString($row['facet_value']), 'count' => $this->asInt($row['facet_count'])];
            }

            return $values;
        };

        if ($query->ownerScope === DataQuery::OWNER_GROUPS) {
            return $compute();
        }

        /** @var list<array{value: string, count: int}> $values */
        $values = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $query->tableId)
            ->getList($key, $compute);

        return $values;
    }

    /**
     * The first row of the query, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(DataQuery $query): ?array
    {
        $result = $this->run($query->withPage(1, 0));

        return $result->rows[0] ?? null;
    }

    /**
     * Read every matching record in stable batches, without collecting them in
     * memory first. Used by exports and background jobs. Without sort keys the
     * batches follow the record id (keyset), so records written meanwhile never
     * shift a batch; with sort keys they follow the sort and the record id.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function batches(DataQuery $query, int $batchSize = 500): \Generator
    {
        $batchSize = max(1, min($batchSize, DataQueryLimits::MAX_BATCH_SIZE));

        if ($query->sorts === []) {
            $lastId = 0;
            while (true) {
                $window = new DataQuery(
                    tableId: $query->tableId,
                    ownerScope: $query->ownerScope,
                    userId: $query->userId,
                    filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [
                        $query->filter,
                        new DataFilter('record_id', DataFilter::OP_GT, $lastId),
                    ]),
                    search: $query->search,
                    sorts: [],
                    limit: $batchSize,
                    offset: 0,
                    columns: $query->columns,
                    excludeDeleted: $query->excludeDeleted,
                    languageId: $query->languageId,
                    allLanguages: $query->allLanguages,
                    timezone: $query->timezone,
                    withTotal: false,
                    optionalColumns: $query->optionalColumns,
                );
                $rows = $this->execute($window)->rows;
                if ($rows === []) {
                    return;
                }
                yield $rows;

                $ids = array_map(fn (array $row): int => $this->asInt($row['record_id']), $rows);
                $lastId = max($ids);
                if (count(array_unique($ids)) < $batchSize) {
                    return;
                }
            }
        }

        $offset = $query->offset;
        while (true) {
            $rows = $this->execute($query->withPage($batchSize, $offset))->rows;
            if ($rows === []) {
                return;
            }
            yield $rows;
            $offset += $batchSize;
            if (count(array_unique(array_map(fn (array $row): int => $this->asInt($row['record_id']), $rows))) < $batchSize) {
                return;
            }
        }
    }

    /**
     * Whether a name is a row field (`record_id`, `entry_date`, ...) rather
     * than a column of the table.
     */
    public static function isRowField(string $name): bool
    {
        return isset(self::ROW_FIELDS[$name]);
    }

    /**
     * The row fields a filter or sort may name besides the columns, with their value types.
     *
     * @return array<string, string> field name => value type
     */
    public static function rowFields(): array
    {
        return array_map(static fn (array $definition): string => $definition['type'], self::ROW_FIELDS);
    }

    /**
     * Columns of a table keyed by `field_key`: id and declared value type.
     *
     * @return array<string, array{id: int, type: string}>
     */
    public function columnsOf(int $tableId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT c.id, c.field_key, lv.lookup_code AS value_type'
            . ' FROM data_cols c LEFT JOIN lookups lv ON lv.id = c.id_value_type'
            . ' WHERE c.id_data_tables = :table AND c.field_key IS NOT NULL ORDER BY c.id',
            ['table' => $tableId],
            ['table' => ParameterType::INTEGER],
        );

        $columns = [];
        foreach ($rows as $row) {
            $type = is_string($row['value_type'] ?? null) ? $row['value_type'] : LookupService::DATA_COL_VALUE_TYPES_TEXT;
            $columns[$this->asString($row['field_key'])] = ['id' => $this->asInt($row['id']), 'type' => $type];
        }

        return $columns;
    }

    /**
     * The columns a query is planned against: the stored ones, plus the optional
     * ones nobody has stored yet. Those have no `data_cols` row (id 0) and so no
     * cells: they read as empty, never as an error, and a filter, a search or a
     * sort on them simply finds nothing to match.
     *
     * @return array<string, array{id: int, type: string}>
     */
    private function columnsFor(DataQuery $query): array
    {
        $columns = $this->columnsOf($query->tableId);
        foreach ($query->optionalColumns as $key => $type) {
            if (!isset($columns[$key])) {
                $columns[$key] = ['id' => 0, 'type' => $type];
            }
        }

        return $columns;
    }

    /**
     * The SQL and parameters of the candidate-id query, for query-plan evidence
     * and tests. Nothing is executed.
     *
     * Author-time checks pass the columns the table WILL have (the inputs of its
     * form that were never submitted have no `data_cols` row yet), so a filter on
     * them is validated before the first record exists. The SQL of such a query
     * is only checked, never run.
     *
     * @param array<string, array{id: int, type: string}>|null $columns columns to plan against instead of the stored ones
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function compile(DataQuery $query, ?array $columns = null): array
    {
        $columns ??= $this->columnsOf($query->tableId);
        $plan = $this->plan($query, $columns, forCount: false);

        return ['sql' => $this->candidateSql($query, $plan), 'params' => $plan->params];
    }

    // -- execution ----------------------------------------------------------------

    private function execute(DataQuery $query): DataQueryResult
    {
        $columns = $this->columnsFor($query);
        $plan = $this->plan($query, $columns, forCount: false);

        $idValues = $this->connection()->fetchFirstColumn($this->candidateSql($query, $plan), $plan->params, $plan->types);
        $ids = array_map(fn (mixed $id): int => $this->asInt($id), $idValues);

        $total = null;
        if ($query->withTotal) {
            $total = $this->count($query);
        }

        return new DataQueryResult($this->hydrate($query, $ids, $columns), $total);
    }

    private function candidateSql(DataQuery $query, QueryPlan $plan): string
    {
        $sql = 'SELECT r.id FROM data_rows r' . $plan->joinSql() . ' WHERE ' . $plan->whereSql() . ' ORDER BY ' . implode(', ', $plan->orderBy);
        if ($query->limit !== null) {
            $sql .= ' LIMIT ' . max(0, $query->limit) . ' OFFSET ' . max(0, $query->offset);
        }

        return $sql;
    }

    // -- planning -----------------------------------------------------------------

    /**
     * @param array<string, array{id: int, type: string}> $columns
     */
    private function plan(DataQuery $query, array $columns, bool $forCount): QueryPlan
    {
        $this->assertBounds($query);

        $plan = new QueryPlan($this->effectiveLanguage($query), $query->timezone, $this->cmsDefaultLanguageId());
        $plan->where[] = 'r.id_data_tables = ' . $plan->bind($query->tableId, ParameterType::INTEGER);

        $this->addOwnerScope($plan, $query);

        if ($query->excludeDeleted) {
            $deletedId = $this->lookupService->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
            if ($deletedId !== null) {
                $plan->where[] = '(r.id_action_trigger_types IS NULL OR r.id_action_trigger_types <> ' . $plan->bind($deletedId, ParameterType::INTEGER) . ')';
            }
        }

        $nodes = 0;
        if (!$query->filter->isEmpty()) {
            $plan->where[] = $this->groupSql($query->filter, $plan, $columns, 1, $nodes);
        }

        if ($query->search !== null) {
            $plan->where[] = $this->searchSql($query->search, $plan, $columns);
        }

        if (!$forCount) {
            $this->addSorts($plan, $query, $columns);
        }

        return $plan;
    }

    private function assertBounds(DataQuery $query): void
    {
        if ($query->limit !== null && ($query->limit < 0 || $query->offset < 0)) {
            throw new DataQueryException('The page window must not be negative.');
        }
        if (count($query->sorts) > DataQueryLimits::MAX_SORTS) {
            throw new DataQueryException(sprintf('At most %d sort keys are allowed.', DataQueryLimits::MAX_SORTS));
        }
        if ($query->columns !== null && count($query->columns) > DataQueryLimits::MAX_PROJECTED_COLUMNS) {
            throw new DataQueryException(sprintf('At most %d columns can be read at once.', DataQueryLimits::MAX_PROJECTED_COLUMNS));
        }
    }

    private function effectiveLanguage(DataQuery $query): int
    {
        return $query->languageId > 0 ? $query->languageId : Language::INDEPENDENT_ID;
    }

    /**
     * CMS default language for the L → independent → D fallback. Null when unset
     * or when it would equal the language-independent row.
     */
    private function cmsDefaultLanguageId(): ?int
    {
        $default = $this->cmsPreferenceService->getDefaultLanguageId();
        if ($default === null || $default === Language::INDEPENDENT_ID) {
            return null;
        }

        return $default;
    }

    /** Suffix so a cached result is never reused after the CMS default language changes. */
    private function cacheKeyDefaultSuffix(): string
    {
        $default = $this->cmsDefaultLanguageId();

        return '|d:' . ($default ?? 'none');
    }

    private function addOwnerScope(QueryPlan $plan, DataQuery $query): void
    {
        if ($query->ownerScope === DataQuery::OWNER_ANY) {
            return;
        }

        $userId = $query->userId;
        if ($userId === null || $userId <= 0) {
            // Own-only reads without a signed-in user match nothing.
            $plan->where[] = '1 = 0';

            return;
        }

        if ($query->ownerScope === DataQuery::OWNER_USER) {
            $plan->where[] = 'r.id_users = ' . $plan->bind($userId, ParameterType::INTEGER);

            return;
        }

        if ($query->ownerScope !== DataQuery::OWNER_GROUPS) {
            throw new DataQueryException('Unknown owner scope.');
        }

        $groupType = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_GROUP);
        if ($groupType === null) {
            $plan->where[] = '1 = 0';

            return;
        }
        $plan->where[] = 'r.id_users IN (SELECT ug.id_users FROM rel_groups_users ug WHERE ug.id_groups IN ('
            . 'SELECT rda.resource_id FROM role_data_access rda'
            . ' INNER JOIN rel_roles_users ur ON ur.id_roles = rda.id_roles'
            . ' WHERE ur.id_users = ' . $plan->bind($userId, ParameterType::INTEGER)
            . ' AND rda.id_resource_types = ' . $plan->bind($groupType, ParameterType::INTEGER)
            . ' AND rda.crud_permissions > 0))';
    }

    /**
     * @param array<string, array{id: int, type: string}> $columns
     */
    private function groupSql(DataFilterGroup $group, QueryPlan $plan, array $columns, int $depth, int &$nodes): string
    {
        if ($depth > DataQueryLimits::MAX_FILTER_DEPTH) {
            throw new DataQueryException(sprintf('Filters may be nested at most %d levels deep.', DataQueryLimits::MAX_FILTER_DEPTH));
        }
        if (!in_array($group->mode, [DataFilterGroup::MODE_AND, DataFilterGroup::MODE_OR], true)) {
            throw new DataQueryException('A filter group combines its filters with "and" or "or".');
        }

        $parts = [];
        foreach ($group->children as $child) {
            $nodes++;
            if ($nodes > DataQueryLimits::MAX_FILTER_NODES) {
                throw new DataQueryException(sprintf('At most %d filters are allowed.', DataQueryLimits::MAX_FILTER_NODES));
            }
            $parts[] = $child instanceof DataFilterGroup
                ? $this->groupSql($child, $plan, $columns, $depth + 1, $nodes)
                : $this->filterSql($child, $plan, $columns);
        }

        if ($parts === []) {
            return '1 = 1';
        }

        return '(' . implode($group->mode === DataFilterGroup::MODE_OR ? ' OR ' : ' AND ', $parts) . ')';
    }

    /**
     * @param array<string, array{id: int, type: string}> $columns
     */
    private function filterSql(DataFilter $filter, QueryPlan $plan, array $columns): string
    {
        $ref = $this->reference($filter->column, $plan, $columns);
        $type = $filter->valueType ?? $ref['type'];
        $this->assertKnownType($type);

        if (in_array($filter->operator, DataFilter::TEXT_ONLY_OPERATORS, true)) {
            return $this->likeSql($filter, $plan, $ref['text']);
        }

        if ($filter->operator === DataFilter::OP_EMPTY) {
            return '(' . $ref['text'] . ' IS NULL OR ' . $ref['text'] . " = '')";
        }
        if ($filter->operator === DataFilter::OP_NOT_EMPTY) {
            return '(' . $ref['text'] . ' IS NOT NULL AND ' . $ref['text'] . " <> '')";
        }

        if ($type === LookupService::DATA_COL_VALUE_TYPES_BOOLEAN && !in_array($filter->operator, [DataFilter::OP_EQ, DataFilter::OP_NEQ], true)) {
            throw new DataQueryException('A yes/no column can only be compared with "eq" or "neq".');
        }

        $typed = $ref['system'] ? $ref['text'] : $this->typedExpression($ref['text'], $type);
        // The value text of a system field of another declared type still has to
        // be read as that type (a legacy filter may compare entry_date with text).
        if ($ref['system'] && $type !== $ref['type']) {
            $typed = $this->typedExpression($ref['text'], $type);
        }

        $value = fn (mixed $one): string => $this->valueSql($plan, $one, $type, $filter->column);

        return match ($filter->operator) {
            DataFilter::OP_EQ => $typed . ' = ' . $value($filter->value),
            DataFilter::OP_NEQ => '(' . $typed . ' IS NULL OR ' . $typed . ' <> ' . $value($filter->value) . ')',
            DataFilter::OP_GT => $typed . ' > ' . $value($filter->value),
            DataFilter::OP_GTE => $typed . ' >= ' . $value($filter->value),
            DataFilter::OP_LT => $typed . ' < ' . $value($filter->value),
            DataFilter::OP_LTE => $typed . ' <= ' . $value($filter->value),
            DataFilter::OP_BETWEEN => $this->betweenSql($filter, $typed, $value),
            DataFilter::OP_IN => $typed . ' IN (' . implode(', ', array_map($value, $this->listValue($filter, null))) . ')',
            DataFilter::OP_NOT_IN => '(' . $typed . ' IS NULL OR ' . $typed . ' NOT IN (' . implode(', ', array_map($value, $this->listValue($filter, null))) . '))',
            default => throw new DataQueryException(sprintf('Unknown filter operator "%s".', $filter->operator)),
        };
    }

    /**
     * @param callable(mixed): string $value turns one filter value into a typed, bound SQL operand
     */
    private function betweenSql(DataFilter $filter, string $typed, callable $value): string
    {
        $bounds = $this->listValue($filter, 2);

        return '(' . $typed . ' BETWEEN ' . $value($bounds[0]) . ' AND ' . $value($bounds[1]) . ')';
    }

    private function likeSql(DataFilter $filter, QueryPlan $plan, string $text): string
    {
        $value = $filter->value;
        if (!is_scalar($value) || is_bool($value)) {
            throw new DataQueryException(sprintf('The "%s" operator needs one text value.', $filter->operator));
        }
        $needle = $this->boundedText((string) $value, $filter->column);
        $escaped = addcslashes($needle, '\\%_');
        $pattern = match ($filter->operator) {
            DataFilter::OP_STARTS_WITH => $escaped . '%',
            DataFilter::OP_ENDS_WITH => '%' . $escaped,
            default => '%' . $escaped . '%',
        };

        return $text . ' LIKE ' . $plan->bind($pattern) . self::LIKE_ESCAPE;
    }

    /**
     * @param array<string, array{id: int, type: string}> $columns
     */
    private function searchSql(DataSearch $search, QueryPlan $plan, array $columns): string
    {
        $term = trim($search->term);
        $length = mb_strlen($term);
        if ($length < DataQueryLimits::SEARCH_MIN_LENGTH || $length > DataQueryLimits::SEARCH_MAX_LENGTH) {
            throw new DataQueryException(sprintf(
                'A search needs between %d and %d characters.',
                DataQueryLimits::SEARCH_MIN_LENGTH,
                DataQueryLimits::SEARCH_MAX_LENGTH
            ));
        }
        if ($search->columns === [] || count($search->columns) > DataQueryLimits::MAX_SEARCH_COLUMNS) {
            throw new DataQueryException(sprintf('A search covers between 1 and %d columns.', DataQueryLimits::MAX_SEARCH_COLUMNS));
        }

        $pattern = $plan->bind('%' . addcslashes($term, '\\%_') . '%');
        $parts = [];
        foreach (array_unique($search->columns) as $columnKey) {
            if (!isset($columns[$columnKey])) {
                throw new DataQueryException(sprintf('Unknown column "%s" in the search.', $columnKey));
            }
            $parts[] = $this->cellText($plan, $columns[$columnKey]['id']) . ' LIKE ' . $pattern . self::LIKE_ESCAPE;
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param array<string, array{id: int, type: string}> $columns
     */
    private function addSorts(QueryPlan $plan, DataQuery $query, array $columns): void
    {
        $firstDirection = 'ASC';
        foreach ($query->sorts as $index => $sort) {
            $direction = strtolower($sort->direction) === DataSort::DESC ? 'DESC' : 'ASC';
            if ($index === 0) {
                $firstDirection = $direction;
            }
            $ref = $this->reference($sort->column, $plan, $columns);
            $this->assertKnownType($ref['type']);
            $typed = $ref['system'] ? $ref['text'] : $this->typedExpression($ref['text'], $ref['type']);
            // Rows without a usable value always come last, in both directions.
            $plan->orderBy[] = '(' . $typed . ' IS NULL) ASC';
            $plan->orderBy[] = $typed . ' ' . $direction;
        }
        $plan->orderBy[] = 'r.id ' . $firstDirection;
    }

    // -- columns and typed expressions ---------------------------------------------

    /**
     * @param array<string, array{id: int, type: string}> $columns
     * @return array{text: string, type: string, system: bool}
     */
    private function reference(string $column, QueryPlan $plan, array $columns): array
    {
        if (isset(self::ROW_FIELDS[$column])) {
            $expression = $column === 'entry_date' ? $plan->entryDateExpression() : self::ROW_FIELDS[$column]['expr'];

            return ['text' => $expression, 'type' => self::ROW_FIELDS[$column]['type'], 'system' => true];
        }
        if (!isset($columns[$column])) {
            throw new DataQueryException(sprintf('Unknown column "%s".', $column));
        }

        return ['text' => $this->cellText($plan, $columns[$column]['id']), 'type' => $columns[$column]['type'], 'system' => false];
    }

    /**
     * The text of a column for each row: the cell of the requested language, else
     * the language-independent cell, else the CMS default language. Each cell is
     * one primary-key lookup.
     */
    private function cellText(QueryPlan $plan, int $columnId): string
    {
        if (isset($plan->cellExpressions[$columnId])) {
            return $plan->cellExpressions[$columnId];
        }

        $index = count($plan->cellExpressions);
        $requested = 'v' . $index;
        $plan->joins[] = ' LEFT JOIN data_cells ' . $requested . ' ON ' . $requested . '.id_data_rows = r.id AND '
            . $requested . '.id_data_cols = ' . $columnId . ' AND ' . $requested . '.id_languages = ' . $plan->languageId;

        if ($plan->languageId === Language::INDEPENDENT_ID) {
            return $plan->cellExpressions[$columnId] = $requested . '.value';
        }

        $independent = 'i' . $index;
        $plan->joins[] = ' LEFT JOIN data_cells ' . $independent . ' ON ' . $independent . '.id_data_rows = r.id AND '
            . $independent . '.id_data_cols = ' . $columnId . ' AND ' . $independent . '.id_languages = ' . Language::INDEPENDENT_ID;

        $cmsDefault = $plan->cmsDefaultLanguageId;
        if ($cmsDefault === null || $cmsDefault === $plan->languageId) {
            return $plan->cellExpressions[$columnId] = 'IF(' . $requested . '.id_data_rows IS NOT NULL, '
                . $requested . '.value, ' . $independent . '.value)';
        }

        $default = 'c' . $index;
        $plan->joins[] = ' LEFT JOIN data_cells ' . $default . ' ON ' . $default . '.id_data_rows = r.id AND '
            . $default . '.id_data_cols = ' . $columnId . ' AND ' . $default . '.id_languages = ' . $cmsDefault;

        return $plan->cellExpressions[$columnId] = 'IF(' . $requested . '.id_data_rows IS NOT NULL, ' . $requested . '.value, '
            . 'IF(' . $independent . '.id_data_rows IS NOT NULL, ' . $independent . '.value, ' . $default . '.value))';
    }

    private function assertKnownType(string $type): void
    {
        $known = [
            LookupService::DATA_COL_VALUE_TYPES_TEXT,
            LookupService::DATA_COL_VALUE_TYPES_NUMBER,
            LookupService::DATA_COL_VALUE_TYPES_DATE,
            LookupService::DATA_COL_VALUE_TYPES_DATETIME,
            LookupService::DATA_COL_VALUE_TYPES_BOOLEAN,
        ];
        if (!in_array($type, $known, true)) {
            throw new DataQueryException(sprintf('Unknown value type "%s".', $type));
        }
    }

    /**
     * The cell text read as a value type; NULL when it is missing or not
     * readable as that type. {@see DataColValueType::sql()} holds the rule, next to its PHP twin.
     */
    private function typedExpression(string $text, string $type): string
    {
        return DataColValueType::sql($text, $type);
    }

    /**
     * A value of a filter, checked for its type and bound as a parameter.
     */
    private function valueSql(QueryPlan $plan, mixed $value, string $type, string $column): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new DataQueryException(sprintf('The value for "%s" must be a single value.', $column));
        }
        if ($value === null) {
            throw new DataQueryException(sprintf('The filter on "%s" needs a value.', $column));
        }

        switch ($type) {
            case LookupService::DATA_COL_VALUE_TYPES_NUMBER:
                if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
                    throw new DataQueryException(sprintf('The value for "%s" must be a number.', $column));
                }

                return 'CAST(' . $plan->bind((string) $value) . ' AS DECIMAL(30,10))';
            case LookupService::DATA_COL_VALUE_TYPES_DATE:
                $text = trim((string) $value, ' ');
                if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $text) !== 1 || DataColValueType::dayOf($text) === null) {
                    throw new DataQueryException(sprintf('The value for "%s" must be a date that exists (YYYY-MM-DD).', $column));
                }

                return 'CAST(' . $plan->bind($text) . ' AS DATE)';
            case LookupService::DATA_COL_VALUE_TYPES_DATETIME:
                // The same reading as a stored cell: a zone (Z or an offset) names an instant, no zone is a UTC clock time.
                $instant = DataColValueType::instantOf((string) $value);
                if ($instant === null) {
                    throw new DataQueryException(sprintf('The value for "%s" must be a date with time that exists (YYYY-MM-DD HH:MM, optionally with Z or an offset).', $column));
                }

                return 'CAST(' . $plan->bind($instant) . ' AS DATETIME)';
            case LookupService::DATA_COL_VALUE_TYPES_BOOLEAN:
                $flag = is_bool($value) ? $value : match (strtolower(trim((string) $value))) {
                    '1', 'true', 'yes', 'on' => true,
                    '0', 'false', 'no', 'off' => false,
                    default => null,
                };
                if ($flag === null) {
                    throw new DataQueryException(sprintf('The value for "%s" must be yes or no.', $column));
                }

                return $plan->bind($flag ? 1 : 0, ParameterType::INTEGER);
            default:
                return $plan->bind($this->boundedText((string) $value, $column));
        }
    }

    private function boundedText(string $text, string $column): string
    {
        if (mb_strlen($text) > DataQueryLimits::MAX_VALUE_LENGTH) {
            throw new DataQueryException(sprintf('The value for "%s" is longer than %d characters.', $column, DataQueryLimits::MAX_VALUE_LENGTH));
        }

        return $text;
    }

    /**
     * @return list<mixed>
     */
    private function listValue(DataFilter $filter, ?int $exactly): array
    {
        $value = $filter->value;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new DataQueryException(sprintf('The "%s" operator needs a list of values.', $filter->operator));
        }
        if ($exactly !== null && count($value) !== $exactly) {
            throw new DataQueryException(sprintf('The "%s" operator needs exactly %d values.', $filter->operator, $exactly));
        }
        if (count($value) > DataQueryLimits::MAX_LIST_VALUES) {
            throw new DataQueryException(sprintf('At most %d values are allowed in a list.', DataQueryLimits::MAX_LIST_VALUES));
        }

        return $value;
    }

    // -- hydration ----------------------------------------------------------------

    /**
     * @param list<int> $ids record ids of the page, in order
     * @param array<string, array{id: int, type: string}> $columns
     * @return list<array<string, mixed>>
     */
    private function hydrate(DataQuery $query, array $ids, array $columns): array
    {
        if ($ids === []) {
            return [];
        }

        $projected = $query->columns === null ? array_keys($columns) : array_values(array_unique($query->columns));
        $columnIds = [];
        foreach ($projected as $key) {
            if (!isset($columns[$key])) {
                throw new DataQueryException(sprintf('Unknown column "%s".', $key));
            }
            $columnIds[$columns[$key]['id']] = $key;
        }

        $meta = $this->metaRows($ids, $query->timezone);
        $language = $this->effectiveLanguage($query);

        if ($query->allLanguages) {
            return $this->hydrateAllLanguages($ids, $meta, $columnIds, $projected);
        }

        $values = [];
        if ($columnIds !== []) {
            $cmsDefault = $this->cmsDefaultLanguageId();
            $languages = $this->fallbackLanguageIds($language, $cmsDefault);
            $cells = $this->connection()->fetchAllAssociative(
                'SELECT c.id_data_rows AS rid, c.id_data_cols AS cid, c.id_languages AS lid, c.`value` AS cell_value'
                . ' FROM data_cells c WHERE c.id_data_rows IN (:ids) AND c.id_data_cols IN (:cols) AND c.id_languages IN (:langs)',
                ['ids' => $ids, 'cols' => array_keys($columnIds), 'langs' => $languages],
                ['ids' => ArrayParameterType::INTEGER, 'cols' => ArrayParameterType::INTEGER, 'langs' => ArrayParameterType::INTEGER],
            );
            foreach ($cells as $cell) {
                $rid = $this->asInt($cell['rid']);
                $key = $columnIds[$this->asInt($cell['cid'])];
                $lid = $this->asInt($cell['lid']);
                $priority = $this->languageFallbackPriority($lid, $language, $cmsDefault);
                $existing = $values[$rid][$key]['priority'] ?? 0;
                if ($priority > $existing) {
                    $values[$rid][$key] = ['value' => $cell['cell_value'], 'priority' => $priority];
                }
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($meta[$id])) {
                continue;
            }
            $row = $meta[$id];
            foreach ($projected as $key) {
                $row[$key] = $values[$id][$key]['value'] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One row per record and language that has at least one cell.
     *
     * @param list<int> $ids
     * @param array<int, array<string, mixed>> $meta
     * @param array<int, string> $columnIds column id => key
     * @param list<string> $projected
     * @return list<array<string, mixed>>
     */
    private function hydrateAllLanguages(array $ids, array $meta, array $columnIds, array $projected): array
    {
        $cells = $this->connection()->fetchAllAssociative(
            'SELECT c.id_data_rows AS rid, c.id_data_cols AS cid, c.id_languages AS lid, c.`value` AS cell_value,'
            . ' lang.locale AS language_locale, lang.`language` AS language_name'
            . ' FROM data_cells c LEFT JOIN languages lang ON lang.id = c.id_languages'
            . ' WHERE c.id_data_rows IN (:ids)'
            . ($columnIds === [] ? '' : ' AND c.id_data_cols IN (:cols)')
            . ' ORDER BY c.id_data_rows, c.id_languages',
            $columnIds === [] ? ['ids' => $ids] : ['ids' => $ids, 'cols' => array_keys($columnIds)],
            $columnIds === [] ? ['ids' => ArrayParameterType::INTEGER] : ['ids' => ArrayParameterType::INTEGER, 'cols' => ArrayParameterType::INTEGER],
        );

        /** @var array<int, array<int, array{id_languages: int, language_locale: mixed, language_name: mixed, values: array<string, mixed>}>> $byRecordAndLanguage */
        $byRecordAndLanguage = [];
        foreach ($cells as $cell) {
            $rid = $this->asInt($cell['rid']);
            $lid = $this->asInt($cell['lid']);
            if (!isset($byRecordAndLanguage[$rid][$lid])) {
                $byRecordAndLanguage[$rid][$lid] = [
                    'id_languages' => $lid,
                    'language_locale' => $cell['language_locale'],
                    'language_name' => $cell['language_name'],
                    'values' => [],
                ];
            }
            $columnKey = $columnIds[$this->asInt($cell['cid'])] ?? null;
            if ($columnKey !== null) {
                $byRecordAndLanguage[$rid][$lid]['values'][$columnKey] = $cell['cell_value'];
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($meta[$id], $byRecordAndLanguage[$id])) {
                continue;
            }
            foreach ($byRecordAndLanguage[$id] as $languageRow) {
                $row = $meta[$id];
                $row['id_languages'] = $languageRow['id_languages'];
                $row['language_locale'] = $languageRow['language_locale'];
                $row['language_name'] = $languageRow['language_name'];
                foreach ($projected as $key) {
                    $row[$key] = $languageRow['values'][$key] ?? null;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Record fields of a page of ids, keyed by record id.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function metaRows(array $ids, string $timezone): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, :tz) AS entry_date, r.id_users,'
            . ' u.`name` AS user_name, (SELECT MIN(vc.code) FROM validation_codes vc WHERE vc.id_users = u.id) AS user_code,'
            . ' r.id_action_trigger_types, l.lookup_code AS triggerType'
            . ' FROM data_rows r'
            . ' LEFT JOIN users u ON u.id = r.id_users'
            . ' LEFT JOIN lookups l ON l.id = r.id_action_trigger_types'
            . ' WHERE r.id IN (:ids)',
            ['tz' => $timezone, 'ids' => $ids],
            ['tz' => ParameterType::STRING, 'ids' => ArrayParameterType::INTEGER],
        );

        $meta = [];
        foreach ($rows as $row) {
            $meta[$this->asInt($row['record_id'])] = $row;
        }

        return $meta;
    }

    /**
     * Language ids to load for a visitor read: requested, then independent, then CMS default.
     *
     * @return list<int>
     */
    private function fallbackLanguageIds(int $language, ?int $cmsDefault): array
    {
        if ($language === Language::INDEPENDENT_ID) {
            return [Language::INDEPENDENT_ID];
        }

        $languages = [$language, Language::INDEPENDENT_ID];
        if ($cmsDefault !== null && $cmsDefault !== $language) {
            $languages[] = $cmsDefault;
        }

        return $languages;
    }

    /**
     * Higher wins when several cells exist for one column: requested > independent > CMS default.
     */
    private function languageFallbackPriority(int $languageId, int $requested, ?int $cmsDefault): int
    {
        if ($languageId === $requested) {
            return 3;
        }
        if ($languageId === Language::INDEPENDENT_ID) {
            return 2;
        }
        if ($cmsDefault !== null && $languageId === $cmsDefault) {
            return 1;
        }

        return 0;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
