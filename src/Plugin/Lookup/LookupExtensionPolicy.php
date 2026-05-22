<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lookup;

/**
 * Lookup-group extension policy.
 *
 *   - `CLOSED`            — core-owned; plugins may read but not extend.
 *   - `PLUGIN_EXTENDABLE` — core-owned; plugins may add entries through
 *                            `plugin.json` lookups block or
 *                            `LookupRegistryEvent`. Added rows are tagged
 *                            with `id_plugins`.
 *   - `PLUGIN_OWNED`      — fully owned by one plugin. The plugin is
 *                            responsible for the type's entire lifecycle.
 *
 * Plugins must NOT directly insert/update/delete lookup rows at runtime.
 * Lookup changes happen through plugin install/update migrations or
 * the plugin manager. Every plugin-owned lookup row must carry
 * `id_plugins`.
 */
final class LookupExtensionPolicy
{
    public const CLOSED = 'closed';
    public const PLUGIN_EXTENDABLE = 'plugin_extendable';
    public const PLUGIN_OWNED = 'plugin_owned';

    public const ALL = [
        self::CLOSED,
        self::PLUGIN_EXTENDABLE,
        self::PLUGIN_OWNED,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
