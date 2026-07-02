<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Entity\NavigationMenuItem;
use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the menu-tree depth cap: top-level (0) + one child level (1) only.
 * CMS page trees may be deeper; this applies to navigation_menu_items only.
 */
final class NavigationMenuDepthSupport
{
    /** Allowed menu depths: 0 (top-level) and 1 (child). Depth 2+ is forbidden. */
    public const MAX_LEVEL = 1;

    /** Menu {@see NavigationMenu::maxDepth} stored value (levels count). */
    public const MENU_MAX_DEPTH = 2;

    public function computeDepth(?NavigationMenuItem $item): int
    {
        if (!$item instanceof NavigationMenuItem) {
            return 0;
        }

        $depth = 0;
        $current = $item->getParentItem();
        while ($current instanceof NavigationMenuItem) {
            ++$depth;
            $current = $current->getParentItem();
        }

        return $depth;
    }

    public function childDepth(?NavigationMenuItem $parent): int
    {
        return $parent instanceof NavigationMenuItem ? $this->computeDepth($parent) + 1 : 0;
    }

    public function isDepthAllowed(?NavigationMenuItem $parent): bool
    {
        return $this->childDepth($parent) <= self::MAX_LEVEL;
    }

    public function assertDepthAllowed(?NavigationMenuItem $parent): void
    {
        if ($this->isDepthAllowed($parent)) {
            return;
        }

        throw new ServiceException(
            'Navigation menus support at most two levels (top-level items and their direct children).',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public function normalizeMenuMaxDepth(?int $maxDepth): int
    {
        if ($maxDepth === null || $maxDepth <= 0) {
            return self::MENU_MAX_DEPTH;
        }

        return min($maxDepth, self::MENU_MAX_DEPTH);
    }
}
