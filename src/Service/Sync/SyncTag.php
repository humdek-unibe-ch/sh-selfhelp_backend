<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

/**
 * The names of a change notice (live-sync contract C1).
 *
 * A notice says WHAT changed, never its content: a client that holds a matching
 * tag refetches through the normal API, so every read is checked by the ACL
 * again. Names and ids only.
 */
final class SyncTag
{
    public const ACL = 'acl';
    public const NAVIGATION = 'navigation';
    public const GLOBAL_VALUES = 'global_values';

    /** The only tag shapes that may appear in a notice. */
    public const PATTERN = '/^(acl|navigation|global_values|page:\d+|page_draft:\d+|page_lock:\d+|table:\d+|section:\d+|asset:\d+)$/';

    private function __construct()
    {
    }

    public static function page(int $pageId): string
    {
        return 'page:' . $pageId;
    }

    public static function pageDraft(int $pageId): string
    {
        return 'page_draft:' . $pageId;
    }

    public static function pageLock(int $pageId): string
    {
        return 'page_lock:' . $pageId;
    }

    public static function table(int $tableId): string
    {
        return 'table:' . $tableId;
    }

    public static function section(int $sectionId): string
    {
        return 'section:' . $sectionId;
    }

    public static function asset(int $assetId): string
    {
        return 'asset:' . $assetId;
    }

    public static function isValid(string $tag): bool
    {
        return preg_match(self::PATTERN, $tag) === 1;
    }
}
