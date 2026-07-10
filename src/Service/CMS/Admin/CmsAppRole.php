<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

/**
 * Strict page-role enum for pages assigned to a CMS app (first-class product unit).
 *
 * Primary roles (all except {@see self::OTHER}) are unique per app.
 */
final class CmsAppRole
{
    public const FORM = 'form';
    public const CMS_LIST = 'cms_list';
    public const CMS_DETAIL = 'cms_detail';
    public const PUBLIC_LIST = 'public_list';
    public const PUBLIC_DETAIL = 'public_detail';
    public const OTHER = 'other';

    /** @var list<string> */
    public const ALL = [
        self::FORM,
        self::CMS_LIST,
        self::CMS_DETAIL,
        self::PUBLIC_LIST,
        self::PUBLIC_DETAIL,
        self::OTHER,
    ];

    /** @var list<string> Roles that may appear at most once per app. */
    public const PRIMARY = [
        self::FORM,
        self::CMS_LIST,
        self::CMS_DETAIL,
        self::PUBLIC_LIST,
        self::PUBLIC_DETAIL,
    ];

    public static function isValid(string $role): bool
    {
        return \in_array($role, self::ALL, true);
    }

    public static function isPrimary(string $role): bool
    {
        return \in_array($role, self::PRIMARY, true);
    }
}
