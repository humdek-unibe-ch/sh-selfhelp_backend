<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * The structured form of an author's SQL filter fragment (`AND status = 'open'
 * ORDER BY entry_date DESC LIMIT 5`), produced by
 * {@see LegacyFilterTranslator}.
 */
final readonly class LegacyFilter
{
    /**
     * @param list<DataSort> $sorts
     */
    public function __construct(
        public DataFilterGroup $filter,
        public array $sorts = [],
        public ?int $limit = null,
        public int $offset = 0,
    ) {
    }
}
