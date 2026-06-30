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
 * Permission matrix for the unified interpolation variable-picker API (issue #56 v2).
 *
 * `GET /admin/interpolation/variables`
 * (AdminInterpolationController::getVariables) is guarded by `admin.page.read`.
 * The QA baseline grants that permission only via the admin role (held only by
 * qa.admin), so the canonical admin-only matrix applies: qa.admin -> 200, every
 * authenticated non-admin -> 403, anonymous -> 401.
 *
 * `context=global` is used because it needs no entity id and always resolves, so
 * the allowed (qa.admin) call returns 200 rather than a 400 validation error.
 */
#[Group('security')]
final class AdminInterpolationPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testInterpolationVariablesEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/interpolation/variables?context=global');
    }
}
