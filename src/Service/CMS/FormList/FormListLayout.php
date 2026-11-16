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
    private function __construct()
    {
    }

    /**
     * The form inside a list: its shallowest descendant of a form style, first by
     * position. A list works with one form; further forms in it are ignored.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    public static function inlineFormSectionId(array $rows, int $listSectionId): ?int
    {
        $best = null;
        foreach ($rows as $row) {
            $id = self::int($row['id'] ?? null);
            if (!in_array($row['style_name'] ?? null, StyleNames::FORM_STYLE_NAMES, true)) {
                continue;
            }
            $ids = self::pathIds($row);
            $index = array_search($listSectionId, $ids, true);
            if ($index === false || $index === count($ids) - 1) {
                continue; // not below the list
            }
            $rank = [self::int($row['level'] ?? null), self::int($row['position'] ?? null), $id];
            if ($best === null || $rank < $best['rank']) {
                $best = ['rank' => $rank, 'id' => $id];
            }
        }

        return $best['id'] ?? null;
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
