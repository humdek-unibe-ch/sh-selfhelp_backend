<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

/**
 * Pure helpers for navigation menu resolution (unit-testable, no DI).
 */
final class NavigationMenuResolveSupport
{
    /**
     * @param list<array<string, mixed>> $rootItems
     *
     * @return list<array<string, mixed>>
     */
    public static function applyRootItemLimit(array $rootItems, ?int $itemLimit): array
    {
        if ($itemLimit === null || $itemLimit <= 0) {
            return $rootItems;
        }

        $sorted = $rootItems;
        usort(
            $sorted,
            static function (array $a, array $b): int {
                $aPos = isset($a['position']) && is_int($a['position']) ? $a['position'] : 0;
                $bPos = isset($b['position']) && is_int($b['position']) ? $b['position'] : 0;

                return $aPos <=> $bPos;
            },
        );

        return array_slice($sorted, 0, $itemLimit);
    }

    /**
     * Page-tree children present in the authoring tree but absent from the public page map.
     *
     * @param list<array<string, mixed>> $authoringChildren
     * @param array<int, array<string, mixed>> $publicPageMap
     * @param list<int> $excludedPageIds
     *
     * @return list<array{page_id: int, keyword: string}>
     */
    public static function hiddenAutoIncludeChildren(
        array $authoringChildren,
        array $publicPageMap,
        array $excludedPageIds,
    ): array {
        $hidden = [];
        foreach ($authoringChildren as $child) {
            $pageId = self::pageIdFromNode($child);
            if ($pageId <= 0 || in_array($pageId, $excludedPageIds, true)) {
                continue;
            }
            if (!array_key_exists($pageId, $publicPageMap) || self::isHeadlessNode($child)) {
                $hidden[] = [
                    'page_id' => $pageId,
                    'keyword' => self::stringOrEmpty($child, 'keyword'),
                ];
            }
        }

        return $hidden;
    }

    /**
     * Page children not represented by explicit menu items (for manual_plus_suggestions).
     *
     * @param list<array<string, mixed>> $authoringChildren
     * @param list<int> $explicitChildPageIds
     *
     * @return list<array{page_id: int, keyword: string}>
     */
    public static function suggestedManualChildren(
        array $authoringChildren,
        array $explicitChildPageIds,
    ): array {
        $explicit = array_fill_keys($explicitChildPageIds, true);
        $out = [];
        foreach ($authoringChildren as $child) {
            $pageId = self::pageIdFromNode($child);
            if ($pageId <= 0 || isset($explicit[$pageId])) {
                continue;
            }
            $out[] = [
                'page_id' => $pageId,
                'keyword' => self::stringOrEmpty($child, 'keyword'),
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private static function pageIdFromNode(array $node): int
    {
        if (isset($node['id_pages']) && is_numeric($node['id_pages'])) {
            return (int) $node['id_pages'];
        }
        if (isset($node['id']) && is_numeric($node['id'])) {
            return (int) $node['id'];
        }

        return 0;
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private static function isHeadlessNode(array $node): bool
    {
        $headless = $node['is_headless'] ?? false;

        return $headless === true || $headless === 1 || $headless === '1';
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private static function stringOrEmpty(array $node, string $key): string
    {
        if (!isset($node[$key]) || !is_scalar($node[$key])) {
            return '';
        }

        return (string) $node[$key];
    }
}
