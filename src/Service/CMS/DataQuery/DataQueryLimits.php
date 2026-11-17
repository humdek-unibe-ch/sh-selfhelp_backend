<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * Bounds of a data query. They keep a request, a configuration and a URL from
 * asking the database for an unbounded amount of work. The page size bound
 * applies to interactive lists; exports and background jobs read in batches
 * through {@see DataQueryEngine::batches()} instead.
 */
final class DataQueryLimits
{
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 25;
    public const MAX_BATCH_SIZE = 1000;
    public const MAX_SORTS = 3;
    public const MAX_FILTER_NODES = 30;
    public const MAX_FILTER_DEPTH = 3;
    public const MAX_LIST_VALUES = 50;
    public const MAX_VALUE_LENGTH = 500;
    public const SEARCH_MIN_LENGTH = 2;
    public const SEARCH_MAX_LENGTH = 100;
    public const MAX_SEARCH_COLUMNS = 20;
    public const MAX_PROJECTED_COLUMNS = 100;

    private function __construct()
    {
    }
}
