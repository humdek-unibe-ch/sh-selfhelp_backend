<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

/**
 * What the first phase of {@see UserDataCleanerService} (inside the caller's
 * transaction) hands to the second phase (after the commit). The caller passes
 * it back unchanged to {@see UserDataCleanerService::finishAfterCommit()}.
 */
final class UserDataRemoval
{
    /**
     * @param array<string, int> $removed rows per kind (for `transactions` and
     *        `data_access_audits` the rows detached from the user, not deleted)
     * @param list<int> $dataTableIds data tables whose rows changed, to clear their caches
     * @param list<\Closure(): void> $afterCommit work that plugins asked to run
     *        after the commit (see {@see \App\Plugin\Event\UserDataCleanupEvent})
     */
    public function __construct(
        public readonly array $removed,
        public readonly array $dataTableIds,
        public readonly array $afterCommit,
    ) {
    }
}
