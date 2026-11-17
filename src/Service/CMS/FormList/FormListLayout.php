<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\Common\StyleNames;

/**
 * Reads the place of a list in the page tree. Pure functions over the rows of
 * `get_page_sections_hierarchical` (`id`, `style_name`, `level`, `position`,
 * `path` = comma separated section ids from the page root down to the row).
 */
final class FormListLayout
{
    public const FORM_INSIDE_LIST_MESSAGE = 'An Entry List with Form is already a form: put the inputs directly in the list.';

    private function __construct()
    {
    }

    /**
     * The form section of a list. An `entry-form-list` without a foreign
     * `data_table` is its own form (returns its own id). A `data-view` and a
     * list bound to another table have no form (null). There is no fallback to
     * a nested form section.
     */
    public static function formSectionIdOf(string $listStyle, int $listSectionId, ?int $foreignDataTableId = null): ?int
    {
        if ($foreignDataTableId !== null) {
            return null;
        }

        return $listStyle === StyleNames::STYLE_ENTRY_FORM_LIST ? $listSectionId : null;
    }

    /**
     * Whether a form-style section sits anywhere below the list (a leftover of
     * the old two-level setup, or an attempted nest). The list itself is not
     * counted.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    public static function hasNestedForm(array $rows, int $listSectionId): bool
    {
        foreach ($rows as $row) {
            $id = self::int($row['id'] ?? null);
            if ($id === $listSectionId) {
                continue;
            }
            if (!in_array($row['style_name'] ?? null, StyleNames::FORM_STYLE_NAMES, true)) {
                continue;
            }
            $ids = self::pathIds($row);
            $index = array_search($listSectionId, $ids, true);
            if ($index !== false && $index < count($ids) - 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every list section on the page. A list placed inside a data view is not one:
     * the row template of a data view leaves forms and lists out, so such a section
     * is never served.
     *
     * @param list<array<array-key, mixed>> $rows
     * @return list<int>
     */
    public static function listSectionIds(array $rows): array
    {
        $dataViews = [];
        foreach ($rows as $row) {
            if (($row['style_name'] ?? null) === StyleNames::STYLE_DATA_VIEW) {
                $dataViews[self::int($row['id'] ?? null)] = true;
            }
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!in_array($row['style_name'] ?? null, StyleNames::LIST_STYLE_NAMES, true)) {
                continue;
            }
            $ancestors = array_slice(self::pathIds($row), 0, -1);
            if (array_intersect($ancestors, array_keys($dataViews)) !== []) {
                continue;
            }
            $ids[self::int($row['id'] ?? null)] = true;
        }

        return array_keys($ids);
    }

    /**
     * Section ids on the path of a row, root first, the row itself last.
     *
     * @param array<array-key, mixed> $row
     * @return list<int>
     */
    public static function pathIds(array $row): array
    {
        $path = is_string($row['path'] ?? null) ? $row['path'] : '';

        return $path === '' ? [] : array_map('intval', explode(',', $path));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
