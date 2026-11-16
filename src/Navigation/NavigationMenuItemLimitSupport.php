<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalises {@see NavigationMenu::itemLimit}: null = unlimited root items;
 * a finite value must be between 1 and {@see self::MAX_ITEM_LIMIT}.
 * Runtime truncate treats {@code <= 0} as unlimited, so writers must never
 * persist 0 — the UI sends null for "unlimited".
 */
final class NavigationMenuItemLimitSupport
{
    public const MAX_ITEM_LIMIT = 50;

    /**
     * @throws ServiceException when the value is an int outside 1..MAX
     */
    public function normalizeMenuItemLimit(mixed $itemLimit): ?int
    {
        if ($itemLimit === null) {
            return null;
        }

        if (!is_int($itemLimit)) {
            throw new ServiceException(
                'item_limit must be null (unlimited) or an integer between 1 and ' . self::MAX_ITEM_LIMIT . '.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($itemLimit < 1 || $itemLimit > self::MAX_ITEM_LIMIT) {
            throw new ServiceException(
                'item_limit must be null (unlimited) or an integer between 1 and ' . self::MAX_ITEM_LIMIT . '.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $itemLimit;
    }
}
