<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Sync;

use App\Service\CMS\Admin\PageDraftWriteRoutes;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The list of draft-write routes (live-sync contracts C8 and C9) must equal the
 * non-GET routes under `/admin/pages/{page_id}/` in `api_routes`, except the lock
 * routes. A new draft-write route that is not in the list would skip the lock and
 * the version check, so this test fails until the route is added or named here as
 * an exception.
 */
#[Group('security')]
final class PageDraftWriteRoutesTest extends QaKernelTestCase
{
    /** Routes under a page that do not write its draft. */
    private const NOT_DRAFT_WRITES = [
        'admin_pages_lock_acquire',
        'admin_pages_lock_release',
        'admin_pages_lock_take_over',
    ];

    public function testTheListEqualsTheWriteRoutesOfAPage(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT route_name, methods FROM api_routes WHERE path LIKE '/admin/pages/{page_id}%'"
        );

        $writes = [];
        foreach ($rows as $row) {
            $name = $row['route_name'];
            $methodList = $row['methods'];
            self::assertIsString($name);
            self::assertIsString($methodList);
            $methods = array_map('trim', explode(',', strtoupper($methodList)));
            if ($methods === ['GET'] || in_array($name, self::NOT_DRAFT_WRITES, true)) {
                continue;
            }
            $writes[] = $name;
        }
        sort($writes);

        $listed = PageDraftWriteRoutes::NAMES;
        sort($listed);

        self::assertSame($writes, $listed, 'Every write route of a page must be guarded (and listed), or named in NOT_DRAFT_WRITES.');
        self::assertContains(PageDraftWriteRoutes::DELETE_PAGE, PageDraftWriteRoutes::NAMES);
    }
}
