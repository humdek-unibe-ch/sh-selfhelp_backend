<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Plugin\Manifest\ResolvedSource;

/**
 * Dispatched by `PluginInstaller::request()` (and from the admin
 * controller for archive/paste sources). Picked up by
 * `InstallPluginHandler` running in the `plugin_ops` Messenger
 * transport worker.
 */
final class InstallPluginMessage
{
    /**
     * @param array<string,mixed> $manifestArray
     */
    public function __construct(
        public readonly int $operationId,
        public readonly array $manifestArray,
        public readonly ResolvedSource $resolvedSource,
    ) {
    }
}
