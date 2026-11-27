<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * A record of a data table was saved (domain event `record.saved`, contract C24).
 *
 * Delivered once per change, after the commit, to the listeners of enabled
 * plugins whose manifest `listens` names `record.saved`. Also for saves made by
 * jobs and imports. It is read-only information: a listener queues jobs, calls
 * {@see \App\Plugin\Resource\PluginResourceServiceInterface::changed()} or sends
 * notices, and never writes data directly.
 */
final class RecordSavedEvent
{
    /**
     * @param int $tableId the data table
     * @param int $recordId the record (`data_rows.id`)
     * @param int|null $ownerUserId the record's owner, null for a record without one
     * @param string $trigger what the save was, as the actions' trigger type code
     * @param string|null $contentRef the content version the record was written for (`content_ref`, C19), when known
     * @param string|null $recordRef which version of the record this event belongs to (`record:<id>@v<n>`, C26)
     */
    public function __construct(
        public readonly int $tableId,
        public readonly int $recordId,
        public readonly ?int $ownerUserId,
        public readonly string $trigger,
        public readonly ?string $contentRef = null,
        public readonly ?string $recordRef = null,
    ) {
    }
}
