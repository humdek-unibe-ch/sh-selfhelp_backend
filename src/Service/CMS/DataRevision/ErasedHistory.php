<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

/**
 * What erasing the history of some records removed, and which stored files that
 * history (and the records' current values) named. The files are deleted only
 * after the caller's transaction committed, because a file cannot roll back.
 */
final readonly class ErasedHistory
{
    /**
     * @param list<string> $fileKeys logical keys (`uploads/form-files/...`) of the stored files
     *        the erased current values and history named; candidates for deletion once committed
     */
    public function __construct(
        public int $revisions = 0,
        public int $changes = 0,
        public int $values = 0,
        public int $actorLinksDetached = 0,
        public array $fileKeys = [],
    ) {
    }
}
