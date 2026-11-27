<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

/**
 * The content reference of a record write (contract C19): which content the
 * record was written for, so research can always establish what a participant
 * was shown and a plugin can keep an unfinished record on the version it began with.
 *
 * Three forms exist, and nothing else is stored:
 *
 *  - `page:<page id>@v<version number>`: a form of a published page version (core forms);
 *  - `page:<page id>@draft`: a form of the draft (an editor's preview, or a page that was never published);
 *  - `res:<type>:<item id>@v<item version>`: a version of a plugin item (the type is a registered resource type).
 *
 * The shape is checked here; whether a `res:` type is registered is checked where a
 * write starts ({@see \App\Service\CMS\DataService}), because only a service can ask.
 */
final class ContentRef
{
    /** The shape of every reference. `D`: no trailing line break is accepted. */
    public const PATTERN = '/^(page:\d+@(v\d+|draft)|res:[a-z][a-z0-9_]{1,63}:\d+@v\d+)$/D';

    /** The size of `data_rows.content_ref` and `data_revisions.content_ref`. */
    public const MAX_LENGTH = 191;

    private const RESOURCE_PATTERN = '/^res:([a-z][a-z0-9_]{1,63}):(\d+)@v(\d+)$/D';

    public static function isWellFormed(string $ref): bool
    {
        return strlen($ref) <= self::MAX_LENGTH && preg_match(self::PATTERN, $ref) === 1;
    }

    /**
     * @throws \InvalidArgumentException when `$ref` is none of the three forms (a programming error)
     */
    public static function assertWellFormed(string $ref): void
    {
        if (!self::isWellFormed($ref)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a content reference: use page:<id>@v<n>, page:<id>@draft or res:<type>:<id>@v<n>.',
                $ref,
            ));
        }
    }

    /**
     * The reference of a page version: the published `$versionNumber`, or the draft when null.
     */
    public static function page(int $pageId, ?int $versionNumber): string
    {
        return $versionNumber === null
            ? sprintf('page:%d@draft', $pageId)
            : sprintf('page:%d@v%d', $pageId, $versionNumber);
    }

    /**
     * The type, item id and version a `res:<type>:<id>@v<n>` reference names; null for a page
     * reference and for anything that is not a well formed reference.
     *
     * @return array{type: string, id: int, version: int}|null
     */
    public static function resource(string $ref): ?array
    {
        if (preg_match(self::RESOURCE_PATTERN, $ref, $match) !== 1) {
            return null;
        }

        return ['type' => $match[1], 'id' => (int) $match[2], 'version' => (int) $match[3]];
    }
}
