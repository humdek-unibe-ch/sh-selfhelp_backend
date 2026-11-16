<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * Working state while {@see DataQueryEngine} builds one SQL statement: the
 * joins for the cells it needs, the conditions, the sort keys and the bound
 * parameters. Values only ever enter SQL through {@see self::bind()}.
 *
 * @internal
 */
final class QueryPlan
{
    /** @var list<string> */
    public array $joins = [];

    /** @var list<string> */
    public array $where = [];

    /** @var list<string> */
    public array $orderBy = [];

    /** @var array<string, mixed> */
    public array $params = [];

    /** @var array<string, ParameterType|ArrayParameterType> */
    public array $types = [];

    /** @var array<int, string> column id => SQL expression of its text */
    public array $cellExpressions = [];

    private int $placeholders = 0;

    private ?string $entryDateExpression = null;

    public function __construct(
        public readonly int $languageId,
        public readonly string $timezone,
    ) {
    }

    /**
     * The entry date of a record as the reader sees it: the stored UTC time
     * moved to the query's time zone, so a filter on `entry_date` compares what
     * the list shows.
     */
    public function entryDateExpression(): string
    {
        if ($this->entryDateExpression !== null) {
            return $this->entryDateExpression;
        }

        $zone = trim($this->timezone);
        if ($zone === '' || strtoupper($zone) === 'UTC') {
            return $this->entryDateExpression = 'r.`timestamp`';
        }

        return $this->entryDateExpression = "CONVERT_TZ(r.`timestamp`, 'UTC', " . $this->bind($zone) . ')';
    }

    /**
     * Bind a value and return its named placeholder.
     */
    public function bind(mixed $value, ParameterType|ArrayParameterType|null $type = null): string
    {
        $name = 'p' . $this->placeholders++;
        $this->params[$name] = $value;
        $this->types[$name] = $type ?? ParameterType::STRING;

        return ':' . $name;
    }

    public function joinSql(): string
    {
        return implode('', $this->joins);
    }

    public function whereSql(): string
    {
        return $this->where === [] ? '1 = 1' : implode(' AND ', $this->where);
    }
}
