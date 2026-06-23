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
 * Permission matrix for the admin mobile-preview mint route
 * (`POST /cms-api/v1/admin/mobile-preview/session`, gated by
 * `admin.mobile_preview.create`).
 *
 * Negative half only: minting writes a one-time code to the cache, so the
 * matrix asserts non-admins get 403 and anonymous gets 401 without minting a
 * real session. The happy path is covered by {@see \App\Tests\Integration\Api\MobilePreviewSessionTest}.
 */
#[Group('security')]
final class MobilePreviewSessionPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testMintSessionIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/mobile-preview/session', ['draft' => false]);
    }
}
