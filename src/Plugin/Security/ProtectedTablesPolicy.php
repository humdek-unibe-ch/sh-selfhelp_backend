<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

/**
 * Single source of truth for the list of tables a plugin must never
 * modify destructively (DROP, TRUNCATE, ALTER, DELETE) or write to via
 * Doctrine entities, unless its manifest carries an explicit
 * `dataAccess.write` grant on top of the matching capability.
 *
 * Used by:
 *   - {@see PluginMigrationGuard} — blocks destructive DDL at migration
 *     execution time (lexical SQL inspection).
 *   - {@see PluginDataAccessGuard} — blocks Doctrine entity writes
 *     from plugin-origin callers at `onFlush` time.
 *   - {@see \App\Plugin\Lifecycle\PluginPurger::collectOwnedTables()}
 *     — refuses purge for plugin manifests that try to declare a
 *     protected table as `dataAccess.ownedTables`.
 *
 * Table names MUST match the canonical schema in
 * `migrations/Version20260501000000.php`. There were historic
 * mismatches (`users_groups` vs `rel_groups_users`, `users_roles`
 * vs `rel_roles_users`, `rel_groups_acls` (no such table) …) which
 * silently bypassed the guard whenever a plugin happened to use the
 * real table name. Keep this list aligned with the schema and add a
 * test if you grow it.
 */
final class ProtectedTablesPolicy
{
    /** @var list<string> */
    public const TABLES = [
        // --- Auth / identity tables ---
        'users',
        'roles',
        'permissions',
        'groups',
        // Relation tables for auth — names taken from the canonical
        // baseline migration. Do not "normalize" these without also
        // updating the entities + repositories.
        'rel_groups_users',
        'rel_roles_users',
        'rel_permissions_roles',
        'rel_api_routes_permissions',
        'refresh_tokens',
        'user_2fa_codes',
        'validation_codes',
        'validation_code_groups',
        'page_acl_groups',
        // --- Core CMS configuration ---
        'pages',
        'sections',
        'page_versions',
        'languages',
        'api_routes',
        // --- Lookups / fields / styles registry ---
        'lookups',
        // --- Plugin layer itself — plugins must never modify these ---
        'plugins',
        'plugin_operations',
        'plugin_sources',
        'plugin_feature_flags',
        // --- Audit trail ---
        'data_access_audits',
        'api_request_logs',
        'transactions',
    ];

    public static function isProtected(string $table): bool
    {
        return in_array(strtolower(trim($table, "`\" ")), self::TABLES, true);
    }
}
