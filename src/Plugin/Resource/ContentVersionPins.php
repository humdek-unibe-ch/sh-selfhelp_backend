<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

use App\Service\CMS\DataRevision\ContentRef;
use App\Service\Core\LookupService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which version of a plugin item a participant's unfinished record is pinned to
 * (contract C19), so a plugin keeps showing the version the participant began on
 * while the item moves on.
 *
 * The answer is read from the records themselves: a record keeps the content
 * reference of its latest write that named one (`data_rows.content_ref`,
 * `res:<type>:<id>@v<n>`), so nothing is stored for the pin. The lookup is one
 * query on `idx_data_rows_table_user_timestamp` (table and user, newest first,
 * trigger type in the index); a participant has few records per table.
 */
final class ContentVersionPins
{
    /** The character that escapes `_` and `%` in the LIKE pattern. */
    private const LIKE_ESCAPE = '!';

    public function __construct(
        private readonly Connection $connection,
        private readonly LookupService $lookups,
    ) {
    }

    /**
     * The item version `$userId`'s newest unfinished record of table `$dataTableId` is pinned to, or null.
     *
     * "Unfinished" is trigger type `started` or `updated`: a finished or a deleted record pins nothing. "Newest" is the
     * latest saved (`timestamp`, then the higher record id). A guest (user id 0), a user without such a record and a
     * record without a reference for this item all give null.
     *
     * @param string $type a registered resource type, checked by the caller
     */
    public function pinnedVersion(int $userId, int $dataTableId, string $type, int $id): ?int
    {
        if ($userId <= 0 || $dataTableId <= 0 || $id <= 0) {
            return null;
        }

        $unfinished = array_values(array_filter([
            $this->lookups->getLookupIdByValue(LookupService::ACTION_TRIGGER_TYPES, LookupService::ACTION_TRIGGER_TYPES_STARTED),
            $this->lookups->getLookupIdByValue(LookupService::ACTION_TRIGGER_TYPES, LookupService::ACTION_TRIGGER_TYPES_UPDATED),
        ], static fn (?int $lookupId): bool => $lookupId !== null));
        if ($unfinished === []) {
            return null;
        }

        $ref = $this->connection->fetchOne(
            'SELECT content_ref FROM data_rows'
            . ' WHERE id_data_tables = :tbl AND id_users = :user AND id_action_trigger_types IN (:unfinished)'
            . ' AND content_ref LIKE :prefix ESCAPE \'' . self::LIKE_ESCAPE . '\''
            . ' ORDER BY `timestamp` DESC, id DESC LIMIT 1',
            [
                'tbl' => $dataTableId,
                'user' => $userId,
                'unfinished' => $unfinished,
                'prefix' => $this->likePrefix(sprintf('res:%s:%d@v', $type, $id)),
            ],
            ['unfinished' => ArrayParameterType::INTEGER],
        );
        if (!is_string($ref)) {
            return null;
        }

        $parsed = ContentRef::resource($ref);

        return $parsed !== null && $parsed['type'] === $type && $parsed['id'] === $id ? $parsed['version'] : null;
    }

    /** `$prefix` as a LIKE pattern for "starts with": the underscores of a type code are not wildcards. */
    private function likePrefix(string $prefix): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '_', '%'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '_', self::LIKE_ESCAPE . '%'],
            $prefix,
        ) . '%';
    }
}
