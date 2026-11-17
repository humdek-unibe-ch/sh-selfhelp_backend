<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * A group of filters combined with AND or OR. Groups nest (bounded by
 * {@see DataQueryLimits::MAX_FILTER_DEPTH}), so a visual builder can express
 * "published AND (category = a OR category = b)". An empty group matches every
 * row.
 */
final readonly class DataFilterGroup
{
    public const MODE_AND = 'and';
    public const MODE_OR = 'or';

    /**
     * @param string $mode `and` or `or`
     * @param list<DataFilter|DataFilterGroup> $children
     */
    public function __construct(
        public string $mode = self::MODE_AND,
        public array $children = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->children === [];
    }
}
