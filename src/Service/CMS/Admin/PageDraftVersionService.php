<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The version of a page's DRAFT (live-sync contract C8): `pages.draft_version`.
 *
 * Every successful draft write raises it by one. A client sends the version it
 * saw and a write based on an older one is refused with 409, so two editors can
 * no longer overwrite each other without knowing (the last-save-wins gap).
 *
 * The check is one conditional UPDATE ({@see claim()}): of two writers that send
 * the same version exactly one changes a row, the other changes none. The value
 * is read and written with SQL, never through the `Page` entity, so a flush of an
 * entity loaded earlier cannot put an old number back.
 *
 * The claim is made BEFORE the write and handed back with {@see unclaim()} when
 * the write fails, so "a successful write raises the version by one" holds and a
 * failed one leaves the number as it was (unless someone else moved it meanwhile).
 */
final class PageDraftVersionService
{
    /** Request header with the version the client saw, response header with the version after the write. */
    public const HEADER = 'X-Page-Version';

    /** Error code of the 400 answer when the header is missing or is not a positive integer. */
    public const ERROR_REQUIRED = 'page_version_required';

    /** Error code of the 409 answer when the sent version is not the current one. */
    public const ERROR_STALE = 'stale_page_version';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The version of the page's draft, or null when the page does not exist.
     */
    public function current(int $pageId): ?int
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT draft_version FROM pages WHERE id = :id',
            ['id' => $pageId],
            ['id' => ParameterType::INTEGER],
        );

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Raise the version from `$expected` to `$expected + 1`, only when it still is `$expected`.
     *
     * @return bool true when this call made the change (the caller is the one writer of this version)
     */
    public function claim(int $pageId, int $expected): bool
    {
        return $this->swap($pageId, $expected, $expected + 1);
    }

    /**
     * Give a claim back after a failed write: back to `$expected`, only when nobody raised it again meanwhile.
     */
    public function unclaim(int $pageId, int $expected): void
    {
        $this->swap($pageId, $expected + 1, $expected);
    }

    private function swap(int $pageId, int $from, int $to): bool
    {
        $changed = $this->entityManager->getConnection()->executeStatement(
            'UPDATE pages SET draft_version = :to WHERE id = :id AND draft_version = :from',
            ['id' => $pageId, 'from' => $from, 'to' => $to],
            ['id' => ParameterType::INTEGER, 'from' => ParameterType::INTEGER, 'to' => ParameterType::INTEGER],
        );

        return (int) $changed === 1;
    }
}
