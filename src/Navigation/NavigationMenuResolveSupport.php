<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Entity\NavigationMenuItem;

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
     * @param list<NavigationMenuItem> $items
     *
     * @return list<NavigationMenuItem>
     */
    public static function resolveRootMenuItems(array $items): array
    {
        /** @var array<int, NavigationMenuItem> $byId */
        $byId = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $byId[$id] = $item;
            }
        }

        $roots = [];
        foreach ($items as $item) {
            $parentId = $item->getParentItem()?->getId();
            if ($parentId === null || !isset($byId[$parentId])) {
                $roots[] = $item;
            }
        }

        usort(
            $roots,
            static fn (NavigationMenuItem $a, NavigationMenuItem $b): int => $a->getPosition() <=> $b->getPosition(),
        );

        return $roots;
    }
}
