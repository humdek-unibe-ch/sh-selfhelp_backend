<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * Visitor search: a plain-text, case-insensitive substring match over the
 * columns the author marked as searchable. `%` and `_` in the term are literal.
 * A search only narrows the configured dataset; it can never widen it.
 */
final readonly class DataSearch
{
    /**
     * @param string $term the visitor's text, {@see DataQueryLimits::SEARCH_MIN_LENGTH} to {@see DataQueryLimits::SEARCH_MAX_LENGTH} characters
     * @param list<string> $columns column `field_key`s, never row fields
     */
    public function __construct(
        public string $term,
        public array $columns,
    ) {
    }
}
