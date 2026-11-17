<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

/**
 * A record as it is while a write holds its lock: read with `SELECT ... FOR UPDATE`,
 * so it is the latest committed state and nobody can change it until the write ends.
 *
 * The guards of an interactive write ({@see RecordWriteContext::$targetGuard}) get
 * this, not the entity the request resolved before the transaction began: the
 * entity may be older than the lock.
 */
final readonly class LockedRecord
{
    public function __construct(
        public int $recordId,
        public int $tableId,
        public int $version,
        public ?int $ownerId,
        public ?int $triggerTypeId,
    ) {
    }
}
