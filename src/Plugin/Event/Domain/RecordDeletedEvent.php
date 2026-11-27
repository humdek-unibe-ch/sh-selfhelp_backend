<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * A record of a data table was deleted (domain event `record.deleted`,
 * contract C24). Delivered once per change, after the commit; read-only, see
 * {@see RecordSavedEvent}.
 */
final class RecordDeletedEvent
{
    /**
     * @param int $tableId the data table
     * @param int $recordId the record that was deleted
     * @param int|null $ownerUserId the record's owner, null for a record without one
     * @param string|null $recordRef which version of the record this event belongs to (`record:<id>@v<n>`, C26)
     */
    public function __construct(
        public readonly int $tableId,
        public readonly int $recordId,
        public readonly ?int $ownerUserId,
        public readonly ?string $recordRef = null,
    ) {
    }
}
