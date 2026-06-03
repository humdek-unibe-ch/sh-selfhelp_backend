<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission matrix for the admin page-keywords lookup API (plan §29).
 *
 * `GET /admin/page-keywords` (Common\LookupController::getPageKeywords) is
 * guarded by `admin.access`. The QA baseline grants that permission only to the
 * admin role (qa.admin), so the canonical admin-only matrix applies: qa.admin →
 * 200, every authenticated non-admin → 403, anonymous → 401.
 */
#[Group('security')]
final class AdminPageKeywordPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testPageKeywordsListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/page-keywords');
    }
}
