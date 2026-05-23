<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

/**
 * Dispatched by `PluginUninstaller::request()`. The Messenger handler
 * runs `composer remove` and then `PluginUninstaller::finalize()`.
 */
final class UninstallPluginMessage
{
    public function __construct(
        public readonly int $operationId,
        public readonly string $pluginId,
    ) {
    }
}
