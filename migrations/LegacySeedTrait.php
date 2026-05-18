<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Helper trait for the four seed migrations following the canonical
 * baseline.
 *
 * The legacy bootstrap data (api_routes, fields, styles, lookups,
 * system pages, etc.) currently lives in `db/legacy/new_create_db.sql`
 * as an SQL dump. Hand-copying every INSERT statement (~600 fields,
 * ~200 styles, ~50 lookup categories, ~120 api_routes) into PHP
 * heredocs would create unmanageable migration files. Instead, each
 * seed migration reads the legacy dump once during `up()`, applies a
 * static old-name → canonical-name rewrite map, and re-emits the
 * inserts against the renamed schema using explicit column lists.
 *
 * After this migration is applied:
 *   - All seed rows are in the canonical schema.
 *   - The legacy file is never read again at runtime.
 *   - `db/legacy/` is purely reference / history.
 */
trait LegacySeedTrait
{
    /**
     * Map of legacy table names → canonical table names.
     *
     * @return array<string,string>
     */
    private function tableRenames(): array
    {
        return [
            'dataTables'                  => 'data_tables',
            'dataRows'                    => 'data_rows',
            'dataCols'                    => 'data_cols',
            'dataCells'                   => 'data_cells',
            'dataAccessAudit'             => 'data_access_audits',
            'scheduledJobs_reminders'     => 'scheduled_job_reminders',
            'scheduledJobs'               => 'scheduled_jobs',
            'refreshTokens'               => 'refresh_tokens',
            'apiRequestLogs'              => 'api_request_logs',
            'callbackLogs'                => 'callback_logs',
            'fieldType'                   => 'field_types',
            'pageType_fields'             => 'rel_fields_page_types',
            'pageType'                    => 'page_types',
            'styleGroup'                  => 'style_groups',
            'logPerformance'              => 'log_performance',
            'users_2fa_codes'             => 'user_2fa_codes',
            'user_activity'               => 'user_activities',
            'users_groups'                => 'rel_groups_users',
            'users_roles'                 => 'rel_roles_users',
            'roles_permissions'           => 'rel_permissions_roles',
            'api_routes_permissions'      => 'rel_api_routes_permissions',
            'pages_sections'              => 'rel_pages_sections',
            'styles_fields'               => 'rel_fields_styles',
            'styles_allowed_relationships'=> 'rel_styles_allowed_relationships',
            'pages_fields'                => 'rel_fields_pages',
            'sections_hierarchy'          => 'rel_sections_hierarchy',
            'sections_navigation'         => 'rel_sections_navigation',
            'acl_groups'                  => 'page_acl_groups',
            'codes_groups'                => 'validation_code_groups',
        ];
    }

    /**
     * Per-column rename map applied within the COLUMN LIST of an INSERT
     * (we rebuild explicit column lists from the legacy CREATE TABLE).
     *
     * @return array<string,string>
     */
    private function columnRenames(): array
    {
        return [
            'id_dataTables'         => 'id_data_tables',
            'id_dataRows'           => 'id_data_rows',
            'id_dataCols'           => 'id_data_cols',
            'id_actionTriggerTypes' => 'id_action_trigger_types',
            'id_assetTypes'         => 'id_asset_types',
            'id_pageAccessTypes'    => 'id_page_access_types',
            'id_userTypes'          => 'id_user_types',
            'id_jobTypes'           => 'id_job_types',
            'id_jobStatus'          => 'id_job_status',
            'id_resourceTypes'      => 'id_resource_types',
            'id_permissionResults'  => 'id_permission_results',
            'id_parentScheduledJobs'=> 'id_parent_scheduled_job',
            'id_scheduledJobs'      => 'id_scheduled_job',
            'id_transactionTypes'   => 'id_transaction_types',
            'id_transactionBy'      => 'id_transaction_by',
            'id_hookTypes'          => 'id_hook_types',
            'id_pageType'           => 'id_page_types',
            'csvSeparator'          => 'csv_separator',
            // sections_hierarchy / sections_navigation: parent/child → id_parent_section / id_child_section
            'parent'                => 'id_parent_section',
            'child'                 => 'id_child_section',
        ];
    }

    /**
     * Per-table column overrides. Some legacy tables have columns that
     * either:
     *   - do NOT exist in the canonical entity (we drop them), or
     *   - need a different rename than the global columnRenames() rule.
     *
     * Returns: [legacyTable => [legacyColumn => canonicalColumn|null]]
     * A `null` value means "drop this column from the insert".
     *
     * @return array<string,array<string,?string>>
     */
    private function columnOverrides(): array
    {
        return [
            // sections_hierarchy and sections_navigation: only column
            // renames; the global "parent" → "id_parent_section" rename
            // handles both. The trait re-resolves on a per-table basis.
            'sections_hierarchy' => [
                'parent' => 'id_parent_section',
                'child'  => 'id_child_section',
            ],
            'sections_navigation' => [
                'parent' => 'id_parent_section',
                'child'  => 'id_child_section',
            ],
            // pages: legacy 'parent' here means the parent page, not a
            // section parent, so we promote it to the canonical
            // id_parent_page. Same for the type/published-version FKs.
            'pages' => [
                'parent'               => 'id_parent_page',
                'id_type'              => 'id_page_types',
                'id_pageAccessTypes'   => 'id_page_access_types',
                'published_version_id' => 'id_published_version',
            ],
        ];
    }

    /**
     * Load and cache the legacy SQL dump.
     */
    private function legacyDump(): string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $candidates = [
            __DIR__ . '/../db/legacy/new_create_db.sql',
            __DIR__ . '/../db/new_create_db.sql',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $cache = (string) file_get_contents($path);
                return $cache;
            }
        }

        $cache = '';
        return $cache;
    }

    /**
     * Extract the ordered column list for a legacy table by parsing
     * its CREATE TABLE definition out of the legacy dump.
     *
     * @return array<int,string>
     */
    private function legacyColumns(string $legacyTable): array
    {
        $dump = $this->legacyDump();
        if ($dump === '') {
            return [];
        }

        $pattern = '/CREATE TABLE `' . preg_quote($legacyTable, '/') . '` \((.*?)\) ENGINE=/s';
        if (!preg_match($pattern, $dump, $m)) {
            return [];
        }

        $body = $m[1];
        $cols = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line === ',') {
                continue;
            }
            // Skip key/constraint definitions
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|CONSTRAINT)\b/i', $line)) {
                continue;
            }
            // Match a column definition: `name` type ...
            if (preg_match('/^`([^`]+)`/', $line, $cm)) {
                $cols[] = $cm[1];
            }
        }
        return $cols;
    }

    /**
     * Apply the legacy → canonical rename to a single column name in the
     * context of a specific legacy table.
     */
    private function renameColumn(string $legacyTable, string $legacyCol): ?string
    {
        $overrides = $this->columnOverrides();
        if (isset($overrides[$legacyTable]) && array_key_exists($legacyCol, $overrides[$legacyTable])) {
            return $overrides[$legacyTable][$legacyCol];
        }

        $global = $this->columnRenames();
        return $global[$legacyCol] ?? $legacyCol;
    }

    /**
     * Extract all `INSERT INTO \`{legacyTable}\` VALUES (...)` rows
     * from the legacy dump and rebuild them as explicit-column-list
     * INSERTs against the canonical schema.
     *
     * @return array<int,string>
     */
    protected function loadLegacyInserts(string $legacyTable): array
    {
        $dump = $this->legacyDump();
        if ($dump === '') {
            return [];
        }

        $renames = $this->tableRenames();
        $canonical = $renames[$legacyTable] ?? $legacyTable;

        $legacyCols = $this->legacyColumns($legacyTable);
        if (empty($legacyCols)) {
            return [];
        }

        // Apply renames + drops
        $canonicalCols = [];
        $keepMask = [];
        foreach ($legacyCols as $col) {
            $newCol = $this->renameColumn($legacyTable, $col);
            if ($newCol === null) {
                $keepMask[] = false;
                continue;
            }
            $canonicalCols[] = $newCol;
            $keepMask[] = true;
        }

        if (empty($canonicalCols)) {
            return [];
        }

        $columnList = '`' . implode('`,`', $canonicalCols) . '`';

        $pattern = '/INSERT INTO `' . preg_quote($legacyTable, '/') . '` VALUES (.*?);\s*$/m';
        if (!preg_match_all($pattern, $dump, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $statements = [];
        foreach ($matches as $m) {
            $rowsBlock = $m[1];
            $rows = $this->splitValueRows($rowsBlock);
            if (empty($rows)) {
                continue;
            }

            $filteredRows = [];
            foreach ($rows as $row) {
                $values = $this->splitRowValues($row);
                if (count($values) !== count($legacyCols)) {
                    // Skip malformed row
                    continue;
                }
                $filtered = [];
                foreach ($values as $i => $v) {
                    if ($keepMask[$i] ?? false) {
                        $filtered[] = $v;
                    }
                }
                $filteredRows[] = '(' . implode(',', $filtered) . ')';
            }

            if (!empty($filteredRows)) {
                $statements[] = 'INSERT INTO `' . $canonical . '` (' . $columnList . ') VALUES ' . implode(',', $filteredRows);
            }
        }

        return $statements;
    }

    /**
     * Split a `(...),(...),(...)` block of value tuples into an array
     * of `(...)` strings, respecting strings with escaped quotes and
     * nested parens inside string literals.
     *
     * @return array<int,string>
     */
    private function splitValueRows(string $block): array
    {
        $rows = [];
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $current = '';
        $len = strlen($block);

        for ($i = 0; $i < $len; $i++) {
            $ch = $block[$i];
            $prev = $i > 0 ? $block[$i - 1] : '';

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && $prev !== '\\') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $current = '(';
                } else {
                    $current .= $ch;
                }
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                $current .= $ch;
                if ($depth === 0) {
                    $rows[] = $current;
                    $current = '';
                }
                continue;
            }

            if ($depth > 0) {
                $current .= $ch;
            }
        }

        return $rows;
    }

    /**
     * Split a single `(v1,v2,v3,...)` row into an array of value tokens
     * (including the surrounding quote characters when present).
     *
     * @return array<int,string>
     */
    private function splitRowValues(string $row): array
    {
        // Strip surrounding parens
        $row = trim($row);
        if (strlen($row) < 2 || $row[0] !== '(' || $row[strlen($row) - 1] !== ')') {
            return [];
        }
        $inner = substr($row, 1, -1);

        $values = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($inner);

        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            $prev = $i > 0 ? $inner[$i - 1] : '';

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && $prev !== '\\') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '(') {
                $depth++;
                $current .= $ch;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                $current .= $ch;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $values[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $values[] = trim($current);
        }

        return $values;
    }

    /**
     * Convenience wrapper: load legacy inserts for `$legacyTable` and
     * register them in the migration's SQL buffer.
     */
    protected function seedFromLegacy(string $legacyTable): void
    {
        foreach ($this->loadLegacyInserts($legacyTable) as $sql) {
            $this->addSql($sql);
        }
    }
}
