<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * One structured comparison of a data column (or a row field) with a value.
 *
 * Every identifier and operator is checked against an allowlist and every
 * value is bound as a SQL parameter by {@see DataQueryEngine}; no part of a
 * filter is ever pasted into SQL. The comparison type comes from the column's
 * declared value type, never from the value.
 *
 * Meaning of the operators for a value that is missing (no cell), empty (an
 * empty text) or not readable as the column type (`abc` in a number column):
 *  - `eq`, `gt`, `gte`, `lt`, `lte`, `between`, `in`, `contains`,
 *    `starts_with`, `ends_with`: such a row never matches;
 *  - `neq`, `not_in`: such a row matches (it is not equal);
 *  - `empty`: matches a missing cell or an empty text; `not_empty` the opposite.
 */
final readonly class DataFilter
{
    public const OP_EQ = 'eq';
    public const OP_NEQ = 'neq';
    public const OP_GT = 'gt';
    public const OP_GTE = 'gte';
    public const OP_LT = 'lt';
    public const OP_LTE = 'lte';
    public const OP_BETWEEN = 'between';
    public const OP_IN = 'in';
    public const OP_NOT_IN = 'not_in';
    public const OP_CONTAINS = 'contains';
    public const OP_STARTS_WITH = 'starts_with';
    public const OP_ENDS_WITH = 'ends_with';
    public const OP_EMPTY = 'empty';
    public const OP_NOT_EMPTY = 'not_empty';

    /** Operators that need no value. */
    public const VALUELESS_OPERATORS = [self::OP_EMPTY, self::OP_NOT_EMPTY];

    /** Operators that take a list of values. */
    public const LIST_OPERATORS = [self::OP_IN, self::OP_NOT_IN, self::OP_BETWEEN];

    /** Operators that only make sense for text. */
    public const TEXT_ONLY_OPERATORS = [self::OP_CONTAINS, self::OP_STARTS_WITH, self::OP_ENDS_WITH];

    public const OPERATORS = [
        self::OP_EQ,
        self::OP_NEQ,
        self::OP_GT,
        self::OP_GTE,
        self::OP_LT,
        self::OP_LTE,
        self::OP_BETWEEN,
        self::OP_IN,
        self::OP_NOT_IN,
        self::OP_CONTAINS,
        self::OP_STARTS_WITH,
        self::OP_ENDS_WITH,
        self::OP_EMPTY,
        self::OP_NOT_EMPTY,
    ];

    /**
     * @param string $column a column `field_key` or a row field (`record_id`, `entry_date`, `id_users`, ...)
     * @param string $operator one of {@see self::OPERATORS}
     * @param mixed $value one scalar, a list of scalars for `in`/`not_in`/`between`, null for `empty`/`not_empty`; the engine rejects anything else
     * @param string|null $valueType overrides the column's declared type (a legacy filter text states it by writing a number without quotes)
     */
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value = null,
        public ?string $valueType = null,
    ) {
    }
}
