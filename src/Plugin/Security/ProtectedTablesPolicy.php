<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Tables a plugin migration must never DROP, TRUNCATE, or DELETE.
 *
 * Used by `PluginMigrationGuard` (Doctrine SQL filter) and by the
 * `selfhelp:plugin:doctor` command.
 *
 * Plugins may still read these tables through *approved core services*
 * and may write them when the matching capability is granted, but they
 * cannot destroy them under any circumstance — purge or otherwise.
 */
final class ProtectedTablesPolicy
{
    /** @var list<string> */
    public const TABLES = [
        // Auth / identity tables
        'users',
        'roles',
        'permissions',
        'groups',
        'users_groups',
        'users_roles',
        'rel_groups_acls',
        'rel_roles_permissions',
        'refresh_tokens',
        'users_2fa_codes',
        'validation_codes',
        // Core CMS configuration
        'pages',
        'sections',
        'page_versions',
        'cms_preferences',
        'languages',
        // Plugin layer itself — plugins must never modify these
        'plugins',
        'plugin_operations',
        'plugin_sources',
        'plugin_feature_flags',
    ];

    public static function isProtected(string $table): bool
    {
        return in_array(strtolower(trim($table, "`\" ")), self::TABLES, true);
    }
}
