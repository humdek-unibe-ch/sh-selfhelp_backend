<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * What {@see DataQueryEngine::aggregate()} computes (contract C17): how to group the
 * records a {@see DataQuery} matches, and which numbers to compute for each group.
 *
 * - `groupBy`: 0 to 2 column keys. Each distinct combination of values is one group (records
 *   without a value form a group of their own, shown as `null`). No columns and no bucket: one
 *   group, the grand total.
 * - `dateBucket`: optional, `{column, unit}` with `unit` one of `day`, `week` (Monday first),
 *   `month`, `year`. The records fall into the calendar period their date or datetime column
 *   names (stored times are UTC, so the periods are UTC periods). A record without a usable
 *   date has no period and is not counted. A bucket combines with `groupBy`.
 * - `metrics`: 1 to 5 of `{fn, column?, as}`. `fn` is `count` (without a column: the records of
 *   the group; with one: the records that have a value there), `sum` or `avg` (a number column),
 *   `min` or `max` (a number, date or datetime column). `as` names the value in the result row
 *   (`^[a-z][a-z0-9_]{0,40}$`, unique, not a column key used for grouping).
 * - `maxGroups`: 1 to 1000, default 200. More groups than that is refused, never cut short.
 *
 * The object only checks its own shape; the engine checks it against the columns of the table.
 * Everything wrong with a spec is an {@see \InvalidArgumentException}.
 */
final class AggregateSpec
{
    public const FN_COUNT = 'count';
    public const FN_SUM = 'sum';
    public const FN_AVG = 'avg';
    public const FN_MIN = 'min';
    public const FN_MAX = 'max';
    public const FUNCTIONS = [self::FN_COUNT, self::FN_SUM, self::FN_AVG, self::FN_MIN, self::FN_MAX];

    public const UNIT_DAY = 'day';
    public const UNIT_WEEK = 'week';
    public const UNIT_MONTH = 'month';
    public const UNIT_YEAR = 'year';
    public const UNITS = [self::UNIT_DAY, self::UNIT_WEEK, self::UNIT_MONTH, self::UNIT_YEAR];

    public const MAX_GROUP_BY = 2;
    public const MAX_METRICS = 5;
    public const MAX_GROUPS_LIMIT = 1000;
    public const DEFAULT_MAX_GROUPS = 200;

    private const ALIAS_PATTERN = '/^[a-z][a-z0-9_]{0,40}$/D';

    /** @var list<string> */
    public readonly array $groupBy;

    /** @var array{column: string, unit: string}|null */
    public readonly ?array $dateBucket;

    /** @var list<array{fn: string, column: string|null, as: string}> */
    public readonly array $metrics;

    public readonly int $maxGroups;

    /**
     * @param array<mixed> $groupBy column keys
     * @param array<mixed>|null $dateBucket `['column' => key, 'unit' => day|week|month|year]`
     * @param array<mixed> $metrics `['fn' => ..., 'column' => key (not for a plain count), 'as' => name]` each
     */
    public function __construct(array $groupBy = [], ?array $dateBucket = null, array $metrics = [], int $maxGroups = self::DEFAULT_MAX_GROUPS)
    {
        if ($maxGroups < 1 || $maxGroups > self::MAX_GROUPS_LIMIT) {
            throw new \InvalidArgumentException(sprintf('maxGroups must be between 1 and %d.', self::MAX_GROUPS_LIMIT));
        }
        $this->maxGroups = $maxGroups;

        $this->groupBy = $this->readGroupBy($groupBy);
        $this->dateBucket = $dateBucket === null ? null : $this->readDateBucket($dateBucket);
        $this->metrics = $this->readMetrics($metrics);

        $taken = $this->groupBy;
        if ($this->dateBucket !== null) {
            if (in_array($this->dateBucket['column'], $taken, true)) {
                throw new \InvalidArgumentException('A column is either grouped by value or bucketed by date, not both.');
            }
            $taken[] = $this->dateBucket['column'];
        }
        foreach ($this->metrics as $metric) {
            if (in_array($metric['as'], $taken, true)) {
                throw new \InvalidArgumentException(sprintf('The metric name "%s" is used twice or is a grouped column.', $metric['as']));
            }
            $taken[] = $metric['as'];
        }
    }

    /**
     * @param array<mixed> $groupBy
     * @return list<string>
     */
    private function readGroupBy(array $groupBy): array
    {
        if (count($groupBy) > self::MAX_GROUP_BY) {
            throw new \InvalidArgumentException(sprintf('At most %d group-by columns are allowed.', self::MAX_GROUP_BY));
        }

        $columns = [];
        foreach ($groupBy as $column) {
            if (!is_string($column) || $column === '') {
                throw new \InvalidArgumentException('A group-by column is a column key.');
            }
            if (in_array($column, $columns, true)) {
                throw new \InvalidArgumentException(sprintf('The column "%s" is grouped twice.', $column));
            }
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @param array<mixed> $bucket
     * @return array{column: string, unit: string}
     */
    private function readDateBucket(array $bucket): array
    {
        $column = $bucket['column'] ?? null;
        $unit = $bucket['unit'] ?? null;
        if (!is_string($column) || $column === '') {
            throw new \InvalidArgumentException('The date bucket needs a column key.');
        }
        if (!is_string($unit) || !in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException(sprintf('The date bucket unit must be one of: %s.', implode(', ', self::UNITS)));
        }

        return ['column' => $column, 'unit' => $unit];
    }

    /**
     * @param array<mixed> $metrics
     * @return list<array{fn: string, column: string|null, as: string}>
     */
    private function readMetrics(array $metrics): array
    {
        if ($metrics === [] || count($metrics) > self::MAX_METRICS) {
            throw new \InvalidArgumentException(sprintf('An aggregation needs between 1 and %d metrics.', self::MAX_METRICS));
        }

        $read = [];
        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                throw new \InvalidArgumentException('A metric is an object with fn and as.');
            }
            $fn = $metric['fn'] ?? null;
            $column = $metric['column'] ?? null;
            $alias = $metric['as'] ?? null;

            if (!is_string($fn) || !in_array($fn, self::FUNCTIONS, true)) {
                throw new \InvalidArgumentException(sprintf('The metric function must be one of: %s.', implode(', ', self::FUNCTIONS)));
            }
            if (!is_string($alias) || preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
                throw new \InvalidArgumentException('A metric name (as) is lower-case letters, digits and underscores, starting with a letter, at most 41 characters.');
            }
            if ($column !== null && (!is_string($column) || $column === '')) {
                throw new \InvalidArgumentException(sprintf('The column of metric "%s" is a column key.', $alias));
            }
            if ($fn !== self::FN_COUNT && $column === null) {
                throw new \InvalidArgumentException(sprintf('The metric "%s" (%s) needs a column.', $alias, $fn));
            }

            $read[] = ['fn' => $fn, 'column' => $column, 'as' => $alias];
        }

        return $read;
    }
}
