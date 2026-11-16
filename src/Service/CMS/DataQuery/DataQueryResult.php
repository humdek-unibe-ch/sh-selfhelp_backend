<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * Rows of one {@see DataQuery}. Each row carries the record fields
 * (`record_id`, `entry_date`, `id_users`, `user_name`, `user_code`,
 * `id_action_trigger_types`, `triggerType`) plus the projected columns keyed by
 * their `field_key`; a column the record has no cell for is `null`. In
 * all-languages mode a row also has `id_languages`, `language_locale` and
 * `language_name`.
 */
final readonly class DataQueryResult
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param int|null $total matching records, only when the query asked for it
     */
    public function __construct(
        public array $rows,
        public ?int $total,
    ) {
    }
}
