<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

/**
 * Dispatched by `PluginPurger::request()`. The Messenger handler runs
 * `composer remove` (dev/trusted modes) or parks a managed-mode runbook
 * for the operator, then `PluginPurger::finalize()` performs the
 * destructive DB/artefact cleanup.
 *
 * `confirmedPluginId` is carried through purely for audit symmetry — the
 * id↔confirmation match is already enforced synchronously in `request()`
 * before this message is ever dispatched.
 */
final class PurgePluginMessage
{
    public function __construct(
        public readonly int $operationId,
        public readonly string $pluginId,
        public readonly string $confirmedPluginId,
        public readonly bool $backupBefore = false,
    ) {
    }
}
