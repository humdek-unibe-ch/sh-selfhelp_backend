<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Plugin\Resource\RegisteredResourceTypes;

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

    /**
     * The only tag shapes that may appear in a notice. `res:<type>:<id>` is an
     * item of a plugin resource type (contract C1); whether the type is
     * registered is checked where the tag is built, not here.
     */
    public const PATTERN = '/^(acl|navigation|global_values|page:\d+|page_draft:\d+|page_lock:\d+|table:\d+|section:\d+|asset:\d+|res:[a-z][a-z0-9_]{1,63}:\d+)$/';

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

    /**
     * The tag of one item of a plugin resource type: `res:<type>:<id>`.
     *
     * This only builds a well-formed tag (a type code that matches
     * `[a-z][a-z0-9_]{1,63}` and a positive id). Whether an enabled plugin
     * registers the type is not known to this static class:
     * `ChangeNoticeService::resourceChanged()` checks it with
     * {@see RegisteredResourceTypes} before it builds the tag, and every
     * later caller must do the same.
     *
     * @throws \InvalidArgumentException for a malformed type code or an id that is not positive
     */
    public static function resource(string $type, int $id): string
    {
        if (preg_match(RegisteredResourceTypes::CODE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid resource type code.', $type));
        }
        if ($id <= 0) {
            throw new \InvalidArgumentException(sprintf('A resource id must be positive, got %d.', $id));
        }

        return 'res:' . $type . ':' . $id;
    }

    public static function isValid(string $tag): bool
    {
        return preg_match(self::PATTERN, $tag) === 1;
    }
}
