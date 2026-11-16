<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * One sort key. Sorting is typed by the column's declared value type, so
 * `9`, `10` and `100` order as numbers in a number column. A row that has no
 * value for the key, or one that cannot be read as the column type, always
 * sorts after the rows that have one, in both directions. The record id is the
 * final tie-breaker so paging is stable.
 */
final readonly class DataSort
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    public function __construct(
        public string $column,
        public string $direction = self::ASC,
    ) {
    }
}
