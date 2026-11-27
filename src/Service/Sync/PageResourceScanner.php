<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Plugin\Resource\ResourceFieldMap;
use App\Service\CMS\Effective\EffectivePage;

/**
 * Which plugin resources a page holds (contract C2 `styles[].resourceFields`,
 * C5, C6 `depends_on`).
 *
 * It reads the page the way {@see EffectivePage} holds it, published or draft,
 * so the answer follows the version the visitor is served. A section holds a
 * resource when its style is one a plugin manifest lists in `resourceFields`
 * and the listed field stores a positive whole number: the id of the item of
 * that resource type ({@see ResourceFieldMap}). Anything else in the field is
 * not a reference and is skipped.
 *
 * The sibling of {@see PageTableScanner}, which does the same for data tables.
 */
final class PageResourceScanner
{
    public function __construct(private readonly ResourceFieldMap $fields)
    {
    }

    /**
     * @return list<array{type: string, id: int}> the resources the page holds, unique, sorted by type and id
     */
    public function scan(EffectivePage $page): array
    {
        $map = $this->fields->styles();
        if ($map === []) {
            return [];
        }

        /** @var array<string, array<int, int>> $found */
        $found = [];
        foreach ($page->rows() as $row) {
            $sectionId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $style = is_string($row['style_name'] ?? null) ? $row['style_name'] : '';
            if ($sectionId <= 0 || !isset($map[$style])) {
                continue;
            }
            foreach ($map[$style] as $field => $type) {
                $id = self::idOf($page->fieldContent($sectionId, $field));
                if ($id > 0) {
                    $found[$type][$id] = $id;
                }
            }
        }

        ksort($found);
        $resources = [];
        foreach ($found as $type => $ids) {
            sort($ids);
            foreach ($ids as $id) {
                $resources[] = ['type' => $type, 'id' => $id];
            }
        }

        return $resources;
    }

    /**
     * The id a stored field value names: a positive whole number, else 0.
     */
    public static function idOf(string $stored): int
    {
        $text = trim($stored);

        return ctype_digit($text) ? (int) $text : 0;
    }
}
