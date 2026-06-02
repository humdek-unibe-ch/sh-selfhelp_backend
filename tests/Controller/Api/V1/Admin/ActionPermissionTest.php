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
 * Negative-permission matrix for the admin actions API (plan §29).
 *
 * The list route `GET /cms-api/v1/admin/actions` is protected by the
 * `admin.action.read` permission. Security tests must assert FAILURE behaviour,
 * not only success: the allowed persona succeeds, every lower-privileged persona
 * is forbidden (403), and an unauthenticated request is rejected (401).
 */
#[Group('security')]
final class ActionPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testActionsListEnforcesAdminOnlyPermissionMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/actions');
    }

    public function testQaAdminListActionsReturnsAnArrayPayload(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/actions', null, $this->loginAsQaAdmin());

        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsArray($data, 'Actions list must return an array data payload.');
    }
}
