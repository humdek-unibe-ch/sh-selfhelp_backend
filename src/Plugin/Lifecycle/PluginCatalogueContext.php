<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Lifecycle;

/**
 * The plugin a {@see PluginCatalogueSynchronizer} run writes for: the
 * `plugins.id` stamped on every row and the manifest id used in errors.
 */
final class PluginCatalogueContext
{
    public function __construct(
        public readonly int $pluginRowId,
        public readonly string $pluginId,
    ) {
    }
}
