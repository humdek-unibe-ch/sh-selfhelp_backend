<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission matrix for the admin permissions catalogue API (plan §29).
 *
 * `GET /admin/permissions` (AdminRoleController::getAllPermissions) is guarded by
 * `admin.permission.read` or `admin.role.update` (F224, decided 2026-11-20: the
 * role editor needs the names and `can_assign`). The QA baseline seeds those
 * rights only on the admin role and the restricted personas, so the canonical
 * admin-only matrix still applies to qa.admin / editor / user / guest, and the
 * restricted personas prove the two accepted rights and the refusal.
 */
#[Group('security')]
final class AdminPermissionsPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const URI = '/cms-api/v1/admin/permissions';

    public function testPermissionsListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::URI);
    }

    public function testRoleEditorWithoutCatalogueRightMayReadTheCatalogue(): void
    {
        $envelope = $this->jsonRequest('GET', self::URI, null, $this->loginAs(QaBaselineFixture::QA_ROLE_UPDATE_EMAIL));

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertNotEmpty($data['permissions'] ?? null, 'The catalogue must list the permissions.');
    }

    public function testCatalogueReaderMayReadTheCatalogue(): void
    {
        $token = $this->loginAs(QaBaselineFixture::readPersonaEmail('admin.permission.read'));

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $token));
    }

    public function testRoleReaderWithoutUpdateOrCatalogueRightIsRefused(): void
    {
        $token = $this->loginAs(QaBaselineFixture::readPersonaEmail('admin.role.read'));

        $this->assertEnvelope403($this->jsonRequest('GET', self::URI, null, $token));
    }

    public function testAnonymousCallerIsAskedToSignIn(): void
    {
        $this->assertEnvelope401($this->jsonRequest('GET', self::URI, null, null));
    }
}
