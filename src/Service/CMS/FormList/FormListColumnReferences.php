<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

/**
 * Where a list names the columns of its table.
 *
 * A list stores the immutable key of a column (`section_<input id>`). Five of
 * its fields hold such keys inside JSON: the columns, the default order, the
 * controls, the fixed filter and the quick actions. A bundle cannot carry the
 * keys (the ids of the inputs differ on every install), so it names the columns
 * by the input names instead, and the keys are written back on import. This
 * class is the one place that knows where in those five fields a column is
 * named; the export and the import both map through it.
 *
 * Pure: it knows no table and no database. The caller passes the mapping.
 */
final class FormListColumnReferences
{
    public const FIELD_COLUMNS = 'list_columns';
    public const FIELD_SORT = 'list_sort';
    public const FIELD_CONTROLS = 'list_controls';
    public const FIELD_FIXED_FILTER = 'list_fixed_filter';
    public const FIELD_QUICK_ACTIONS = 'quick_actions';

    /**
     * The fields of a list section that name columns.
     */
    public const FIELDS = [
        self::FIELD_COLUMNS,
        self::FIELD_SORT,
        self::FIELD_CONTROLS,
        self::FIELD_FIXED_FILTER,
        self::FIELD_QUICK_ACTIONS,
    ];

    /**
     * The same depth as the list reader accepts, plus the levels of a nested filter.
     */
    private const MAX_JSON_DEPTH = 16;

    private function __construct()
    {
    }

    /**
     * The JSON of one field with every column name passed through `$map`.
     *
     * @param string $field one of {@see self::FIELDS}
     * @param string $json the stored text of the field
     * @param callable(string): string $map returns the replacement of a column name, or the name itself
     * @return string|null the new JSON; null when nothing changed, the text is empty or it is not JSON of the expected shape
     */
    public static function rewrite(string $field, string $json, callable $map): ?string
    {
        $json = trim($json);
        if ($json === '' || !in_array($field, self::FIELDS, true)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        $changed = false;
        $rewritten = match ($field) {
            self::FIELD_FIXED_FILTER => self::rewriteFilter($decoded, $map, $changed),
            self::FIELD_QUICK_ACTIONS => self::rewriteActions($decoded, $map, $changed),
            default => self::rewriteItems($decoded, 'field', $map, $changed),
        };
        if (!$changed) {
            return null;
        }

        $encoded = json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? null : $encoded;
    }

    /**
     * @param array<array-key, mixed> $items
     * @param callable(string): string $map
     * @return array<array-key, mixed>
     */
    private static function rewriteItems(array $items, string $key, callable $map, bool &$changed): array
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && is_string($item[$key] ?? null)) {
                $item[$key] = self::mapped($item[$key], $map, $changed);
                $items[$index] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $actions
     * @param callable(string): string $map
     * @return array<array-key, mixed>
     */
    private static function rewriteActions(array $actions, callable $map, bool &$changed): array
    {
        foreach ($actions as $index => $action) {
            if (!is_array($action)) {
                continue;
            }
            $assigned = $action['set'] ?? null;
            if (is_array($assigned)) {
                $set = [];
                foreach ($assigned as $column => $value) {
                    $set[is_string($column) ? self::mapped($column, $map, $changed) : $column] = $value;
                }
                $action['set'] = $set;
            }
            $condition = $action['when'] ?? null;
            if (is_array($condition)) {
                $action['when'] = self::rewriteFilter($condition, $map, $changed);
            }
            $actions[$index] = $action;
        }

        return $actions;
    }

    /**
     * A filter is a group `{mode, children: [...]}` whose children are conditions
     * `{column, operator, value}` or further groups.
     *
     * @param array<array-key, mixed> $group
     * @param callable(string): string $map
     * @return array<array-key, mixed>
     */
    private static function rewriteFilter(array $group, callable $map, bool &$changed): array
    {
        $children = $group['children'] ?? null;
        if (!is_array($children)) {
            return $group;
        }

        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (array_key_exists('children', $child)) {
                $children[$index] = self::rewriteFilter($child, $map, $changed);
            } elseif (is_string($child['column'] ?? null)) {
                $child['column'] = self::mapped($child['column'], $map, $changed);
                $children[$index] = $child;
            }
        }
        $group['children'] = $children;

        return $group;
    }

    /**
     * @param callable(string): string $map
     */
    private static function mapped(string $name, callable $map, bool &$changed): string
    {
        $new = $map($name);
        if ($new !== $name) {
            $changed = true;
        }

        return $new;
    }
}
