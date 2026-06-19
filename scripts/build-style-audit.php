<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * Style/field cross-repo audit generator.
 *
 *   php scripts/build-style-audit.php
 *
 * Produces `docs/reference/styles/style-field-audit.generated.json`, the
 * machine-readable source of truth for the style documentation audit
 * (docs/reference/styles/style-field-audit.md). It fuses:
 *
 *   1. the LIVE DB catalog (styles / fields / rel_fields_styles / render target),
 *      read through DATABASE_URL — the authoritative installed catalog;
 *   2. the `@selfhelp/shared` registry + per-style TS interfaces;
 *   3. the web (`sh-selfhelp_frontend`) BasicStyle dispatch map;
 *   4. the mobile (`sh-selfhelp_mobile`) styleImpls dispatch map;
 *
 * and computes scope distribution, catalog parity, DB<->shared-type drift, and
 * duplicate/typo candidates. Sibling repo locations default to siblings of the
 * project root and can be overridden:
 *
 *   SH_SHARED_DIR / SH_FRONTEND_DIR / SH_MOBILE_DIR
 *
 * Cross-repo sections degrade gracefully (emit an empty set + a warning) when a
 * repo is absent, so the DB-only audit still generates anywhere DATABASE_URL is
 * reachable. Read-only: it never writes to the database.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
(new Dotenv())->bootEnv($root . '/.env');

$sharedDir = getenv('SH_SHARED_DIR') ?: $root . '/../sh-selfhelp_shared';
$frontendDir = getenv('SH_FRONTEND_DIR') ?: $root . '/../sh-selfhelp_frontend';
$mobileDir = getenv('SH_MOBILE_DIR') ?: $root . '/../sh-selfhelp_mobile';

$warnings = [];

// ---------------------------------------------------------------------------
// 1. Live DB catalog (authoritative).
// ---------------------------------------------------------------------------
$url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!$url) {
    fwrite(STDERR, "DATABASE_URL is not set.\n");
    exit(1);
}
$p = parse_url((string) $url);
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['host'], $p['port'] ?? 3306, ltrim($p['path'] ?? '', '/')),
    urldecode($p['user'] ?? ''),
    urldecode($p['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$styles = $pdo->query("
    SELECT s.id, s.name, sg.name AS style_group, s.can_have_children, s.description,
           l.lookup_code AS render_target, pl.name AS plugin
    FROM styles s
    LEFT JOIN style_groups sg ON sg.id = s.id_style_groups
    LEFT JOIN lookups l ON l.id = s.id_render_target
    LEFT JOIN plugins pl ON pl.id = s.id_plugins
    ORDER BY sg.name, s.name
")->fetchAll();

$fieldRows = $pdo->query("
    SELECT rfs.id_styles, f.name AS field_name, ft.name AS field_type,
           f.display, rfs.default_value, rfs.help
    FROM rel_fields_styles rfs
    JOIN fields f ON f.id = rfs.id_fields
    LEFT JOIN field_types ft ON ft.id = f.id_field_types
    ORDER BY f.display DESC, f.name
")->fetchAll();
$fieldsByStyle = [];
foreach ($fieldRows as $r) {
    $fieldsByStyle[$r['id_styles']][] = $r;
}

// ---------------------------------------------------------------------------
// 2. Shared registry + per-style interface fields.
// ---------------------------------------------------------------------------
$registry = [];
$registryFile = $sharedDir . '/src/registry/styles.registry.ts';
if (is_file($registryFile)) {
    $src = (string) file_get_contents($registryFile);
    if (preg_match('/BASE_STYLE_REGISTRY\s*=\s*\{(.*?)\}\s*as const/s', $src, $m)) {
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match("/^\s*'?([\w-]+)'?:\s*\{/", $line, $km)) {
                $registry[$km[1]] = [
                    'category' => preg_match("/category:\s*'([\w-]+)'/", $line, $cm) ? $cm[1] : null,
                    'canHaveChildren' => preg_match('/canHaveChildren:\s*(true|false)/', $line, $hm) ? ($hm[1] === 'true') : null,
                    'platforms' => preg_match('/platforms:\s*\[([^\]]*)\]/', $line, $pm) ? trim($pm[1]) : null,
                ];
            }
        }
    }
} else {
    $warnings[] = "shared registry not found at $registryFile";
}

$typeFields = [];
foreach (glob($sharedDir . '/src/types/styles/*.ts') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/export interface (I\w+Style)[^\{]*\{(.*?)\n\}/s', $src, $blocks, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($blocks as $b) {
        if (!preg_match("/style_name:\s*'([\w-]+)'/", $b[2], $sn)) {
            continue;
        }
        $fields = [];
        foreach (explode("\n", $b[2]) as $line) {
            if (preg_match('/^\s*([a-z_][\w]*)\??:/', $line, $fm) && $fm[1] !== 'style_name') {
                $fields[] = $fm[1];
            }
        }
        $typeFields[$sn[1]] = array_values(array_unique($fields));
    }
}

// ---------------------------------------------------------------------------
// 3 + 4. Web + mobile dispatch map keys.
// ---------------------------------------------------------------------------
$dispatchKeys = static function (string $file, string $marker) use (&$warnings): array {
    if (!is_file($file)) {
        $warnings[] = "dispatch map not found at $file";
        return [];
    }
    $src = (string) file_get_contents($file);
    $pos = strpos($src, $marker);
    if ($pos === false) {
        $warnings[] = "marker '$marker' not found in $file";
        return [];
    }
    $reserved = ['style', 'styleProps', 'cssClass', 'parentActive', 'childIndex', 'parentColor'];
    $keys = [];
    foreach (explode("\n", substr($src, $pos, 40000)) as $line) {
        if (preg_match("/^\s*'?([\w-]+)'?:\s*(\(|\w)/", $line, $km) && !in_array($km[1], $reserved, true)) {
            $keys[$km[1]] = true;
        }
        if (preg_match('/^\};/', $line) && $keys) {
            break;
        }
    }
    return array_keys($keys);
};
$webKeys = $dispatchKeys($frontendDir . '/src/app/components/frontend/styles/BasicStyle.tsx', 'const styleImpls: Record<string, TStyleRenderer>');
$mobileKeys = $dispatchKeys($mobileDir . '/components/styles/index.ts', 'export const styleImpls: TStyleImplMap');
$webSet = array_fill_keys($webKeys, true);
$mobileSet = array_fill_keys($mobileKeys, true);

// Fields contributed by base interfaces (IBaseStyle / IStyleWithSpacing) — not
// per-style drift when absent from a per-style interface body.
$baseInherited = ['web_spacing_margin', 'shared_spacing', 'css', 'css_mobile', 'condition', 'debug', 'data_config'];

// ---------------------------------------------------------------------------
// Fuse.
// ---------------------------------------------------------------------------
$report = [];
$scopeTotals = ['content' => 0, 'common' => 0, 'shared' => 0, 'web' => 0, 'mobile' => 0];
$fieldUse = [];
$deriveScope = static function (string $name, int $display): string {
    if ($display === 1) {
        return 'content';
    }
    foreach (['shared_' => 'shared', 'web_' => 'web', 'mobile_' => 'mobile'] as $prefix => $scope) {
        if (str_starts_with($name, $prefix)) {
            return $scope;
        }
    }
    return 'common';
};

foreach ($styles as $s) {
    $fields = [];
    $scopes = ['content' => 0, 'common' => 0, 'shared' => 0, 'web' => 0, 'mobile' => 0];
    foreach ($fieldsByStyle[$s['id']] ?? [] as $f) {
        $scope = $deriveScope($f['field_name'], (int) $f['display']);
        $scopes[$scope]++;
        $scopeTotals[$scope]++;
        $fieldUse[$f['field_name']] = ($fieldUse[$f['field_name']] ?? 0) + 1;
        $fields[$f['field_name']] = [
            'type' => $f['field_type'],
            'scope' => $scope,
            'display' => (int) $f['display'],
            'default' => $f['default_value'],
        ];
    }
    $name = $s['name'];
    $dbNames = array_keys($fields);
    $tNames = $typeFields[$name] ?? null;

    $dupes = [];
    if (isset($fields['value'], $fields['content']) && $fields['value']['display'] === 0 && $fields['content']['display'] === 1) {
        $dupes[] = 'value (display0) duplicates translatable content';
    }

    $report[$name] = [
        'id' => (int) $s['id'],
        'group' => $s['style_group'],
        'category_shared' => $registry[$name]['category'] ?? null,
        'render_target_db' => $s['render_target'],
        'registry_platforms' => $registry[$name]['platforms'] ?? null,
        'can_have_children_db' => (int) $s['can_have_children'],
        'can_have_children_shared' => $registry[$name]['canHaveChildren'] ?? null,
        'in_shared_registry' => isset($registry[$name]),
        'has_shared_type' => $tNames !== null,
        'web_renderer' => isset($webSet[$name]),
        'mobile_renderer' => isset($mobileSet[$name]),
        'field_count' => count($fields),
        'scope_counts' => $scopes,
        'fields' => $fields,
        'drift_db_fields_missing_from_type' => $tNames === null ? [] : array_values(array_diff($dbNames, $tNames, $baseInherited)),
        'drift_type_fields_missing_from_db' => $tNames === null ? [] : array_values(array_diff($tNames, $dbNames, $baseInherited)),
        'duplicate_candidates' => $dupes,
    ];
}

$dbNamesAll = array_column($styles, 'name');
arsort($fieldUse);

$out = [
    'generated_at' => date('c'),
    'generator' => 'scripts/build-style-audit.php',
    'note' => 'Source of truth = live DB catalog + cross-repo TS. Regenerate after schema/renderer changes.',
    'warnings' => $warnings,
    'totals' => [
        'styles_db' => count($styles),
        'styles_shared_registry' => count($registry),
        'styles_web_renderer' => count($webKeys),
        'styles_mobile_renderer' => count($mobileKeys),
        'distinct_fields_in_use' => count($fieldUse),
        'scope_field_instances' => $scopeTotals,
    ],
    'catalog_parity' => [
        'in_registry_not_db' => array_values(array_diff(array_keys($registry), $dbNamesAll)),
        'in_web_not_db' => array_values(array_diff($webKeys, $dbNamesAll)),
        'in_mobile_not_db' => array_values(array_diff($mobileKeys, $dbNamesAll)),
        'in_db_not_web' => array_values(array_diff($dbNamesAll, $webKeys)),
        'in_db_not_mobile' => array_values(array_diff($dbNamesAll, $mobileKeys)),
        'in_db_not_registry' => array_values(array_diff($dbNamesAll, array_keys($registry))),
    ],
    'most_shared_fields' => array_slice($fieldUse, 0, 60, true),
    'styles' => $report,
];

$target = $root . '/docs/reference/styles/style-field-audit.generated.json';
file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

printf(
    "Wrote %s\n  styles: db=%d registry=%d web=%d mobile=%d | fields=%d | scopes=%s\n",
    'docs/reference/styles/style-field-audit.generated.json',
    count($styles),
    count($registry),
    count($webKeys),
    count($mobileKeys),
    count($fieldUse),
    json_encode($scopeTotals)
);
if ($warnings) {
    fwrite(STDERR, "warnings:\n - " . implode("\n - ", $warnings) . "\n");
}
