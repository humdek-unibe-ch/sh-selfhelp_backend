<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Exception\ServiceException;
use App\Service\Core\LookupService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rules for the web header top row (`navigation_menu_items.layer`).
 *
 * `layer = 'top'` assigns a web_header ROOT item to the top utility row of the
 * double-header presets; `null` means the main row. Top-row items are flat
 * links by design: they cannot have children and nothing can nest under them.
 * Layer assignments are data — single-row presets merge both rows at render
 * time without mutating items, so switching presets back restores the split.
 */
final class NavigationHeaderLayerSupport
{
    public const LAYER_TOP = 'top';

    /**
     * @throws ServiceException when the value is not `'top'` or null/empty
     */
    public function normalizeLayer(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value === self::LAYER_TOP) {
            return self::LAYER_TOP;
        }

        throw new ServiceException(
            sprintf('Unknown navigation layer "%s"; allowed values are "top" or null.', is_scalar($value) ? (string) $value : gettype($value)),
            Response::HTTP_BAD_REQUEST,
        );
    }

    public function isHeaderMenu(NavigationMenu $menu): bool
    {
        return $menu->getMenuKey()?->getLookupCode() === LookupService::NAVIGATION_MENU_KEY_WEB_HEADER;
    }

    /**
     * A non-null layer is only valid on web_header root items.
     */
    public function assertLayerAssignable(NavigationMenu $menu, ?NavigationMenuItem $parentItem, ?string $layer): void
    {
        if ($layer === null) {
            return;
        }
        if (!$this->isHeaderMenu($menu)) {
            throw new ServiceException(
                'Only web header items can be assigned to the top row.',
                Response::HTTP_BAD_REQUEST,
            );
        }
        if ($parentItem !== null) {
            throw new ServiceException(
                'Only top-level header items can be assigned to the top row.',
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * Nothing may nest under a top-row link.
     */
    public function assertParentNotTopLayer(?NavigationMenuItem $parent): void
    {
        if ($parent instanceof NavigationMenuItem && $parent->getLayer() === self::LAYER_TOP) {
            throw new ServiceException(
                'Items cannot be nested under a top-row link.',
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * Top-row items are flat links; reject the assignment while children exist.
     */
    public function assertNoChildrenForTopLayer(?string $layer, int $childCount): void
    {
        if ($layer === self::LAYER_TOP && $childCount > 0) {
            throw new ServiceException(
                'Top-row links cannot have sub-items. Move or remove the children first.',
                Response::HTTP_BAD_REQUEST,
            );
        }
    }
}
