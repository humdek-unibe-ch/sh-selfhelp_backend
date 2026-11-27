<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Versioning;

/**
 * A version reference: which version of a versioned thing a job, an event or a
 * later reader belongs to (contract C26, concepts "Versions", rule 6b).
 *
 * Shape: `<kind>:<id>@v<n>`, where `<kind>` is `record`, `page`, `action`,
 * `asset`, or `res:<type>` for a plugin item. Never a copy of the content.
 *
 * Distinct from {@see \App\Service\CMS\DataRevision\ContentRef}: that names
 * which content a record write was based on (`page:…@draft` included); this
 * names which version of a thing a later step belongs to (`@v<n>` only).
 */
final class VersionRef
{
    /** The size of `scheduled_jobs.record_ref` and of every stored reference. */
    public const MAX_LENGTH = 191;

    /**
     * Every accepted reference. `D`: no trailing line break.
     * Kinds: record, page, action, asset, and res:&lt;type&gt; (type as in C1).
     */
    public const PATTERN = '/^(?:(?:record|page|action|asset):[1-9]\d*|res:[a-z][a-z0-9_]{1,63}:[1-9]\d*)@v[1-9]\d*$/D';

    private const PARSE_PATTERN = '/^(?P<kind>(?:record|page|action|asset)|res:[a-z][a-z0-9_]{1,63}):(?P<id>[1-9]\d*)@v(?P<version>[1-9]\d*)$/D';

    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/D';

    /**
     * The reference of a record version (`record:<id>@v<n>`).
     *
     * @throws \InvalidArgumentException when the id or version is not a positive integer
     */
    public static function record(int $recordId, int $version): string
    {
        return self::of('record', $recordId, $version);
    }

    /**
     * The reference of a page version (`page:<id>@v<n>`). Not a draft: drafts are
     * content references only ({@see \App\Service\CMS\DataRevision\ContentRef}).
     *
     * @throws \InvalidArgumentException when the id or version is not a positive integer
     */
    public static function page(int $pageId, int $version): string
    {
        return self::of('page', $pageId, $version);
    }

    /**
     * The reference of an action version (`action:<id>@v<n>`). Actions have no
     * versions yet (F283); the builder is here so callers share one shape.
     *
     * @throws \InvalidArgumentException when the id or version is not a positive integer
     */
    public static function action(int $actionId, int $version): string
    {
        return self::of('action', $actionId, $version);
    }

    /**
     * The reference of an asset version (`asset:<id>@v<n>`). Assets have no
     * versions yet (F285); the builder is here so callers share one shape.
     *
     * @throws \InvalidArgumentException when the id or version is not a positive integer
     */
    public static function asset(int $assetId, int $version): string
    {
        return self::of('asset', $assetId, $version);
    }

    /**
     * The reference of a plugin item version (`res:<type>:<id>@v<n>`).
     *
     * @throws \InvalidArgumentException when the type, id or version is not valid
     */
    public static function resource(string $type, int $id, int $version): string
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a resource type for a version reference.',
                $type,
            ));
        }

        return self::of('res:' . $type, $id, $version);
    }

    public static function isWellFormed(string $ref): bool
    {
        return strlen($ref) <= self::MAX_LENGTH && preg_match(self::PATTERN, $ref) === 1;
    }

    /**
     * The kind, id and version a reference names; null when it is malformed or too long.
     *
     * @return array{kind: string, id: int, version: int}|null
     */
    public static function parse(string $ref): ?array
    {
        if (strlen($ref) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match(self::PARSE_PATTERN, $ref, $match) !== 1) {
            return null;
        }

        return [
            'kind' => $match['kind'],
            'id' => (int) $match['id'],
            'version' => (int) $match['version'],
        ];
    }

    /**
     * @throws \InvalidArgumentException when `$id` or `$version` is not a positive integer,
     *                                   or the built reference is longer than {@see self::MAX_LENGTH}
     */
    private static function of(string $kind, int $id, int $version): string
    {
        if ($id < 1 || $version < 1) {
            throw new \InvalidArgumentException(sprintf(
                'A version reference needs a positive id and version; got %s:%d@v%d.',
                $kind,
                $id,
                $version,
            ));
        }

        $ref = sprintf('%s:%d@v%d', $kind, $id, $version);
        if (strlen($ref) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'A version reference must be at most %d characters; "%s" is longer.',
                self::MAX_LENGTH,
                $ref,
            ));
        }

        return $ref;
    }
}
