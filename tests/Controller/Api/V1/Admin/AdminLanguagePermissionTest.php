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
 * Permission matrix for the admin languages API (plan §29).
 *
 * The read route asserts the full admin-only matrix (qa.admin → 200, non-admins
 * → 403, anonymous → 401). The create/update/delete routes assert only the
 * negative half (with qa_-prefixed bodies and a non-existent id) so the matrix
 * test never mutates a real language. The QA baseline grants the language
 * permissions only to the admin role, so non-admins are rejected by the
 * permission gate before any controller logic runs.
 */
#[Group('security')]
final class AdminLanguagePermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testLanguagesListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/languages');
    }

    public function testLanguageCreateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/languages', [
            'locale' => 'qa-Xa',
            'language' => 'qa_perm_probe_should_not_create',
            'csv_separator' => ',',
        ]);
    }

    public function testLanguageUpdateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('PUT', '/cms-api/v1/admin/languages/2147483600', [
            'locale' => 'qa-Xb',
            'language' => 'qa_perm_probe_should_not_update',
            'csv_separator' => ',',
        ]);
    }

    public function testLanguageDeleteIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/languages/2147483600');
    }
}
