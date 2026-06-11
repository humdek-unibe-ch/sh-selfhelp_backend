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
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission matrix for the admin registration-code routes (plan §26 / §29).
 *
 *   GET  /admin/registration-codes          -> admin.registration_code.read
 *   GET  /admin/registration-codes/export   -> admin.registration_code.read
 *   POST /admin/registration-codes/generate -> admin.registration_code.create
 *
 * The QA baseline grants the single admin role only to qa.admin, so for every
 * route the matrix is: qa.admin allowed, qa.editor/qa.user/qa.guest 403,
 * anonymous 401.
 *
 * The read (list) route uses the full {@see assertAdminOnlyMatrix} (its success
 * path is a non-mutating GET). The write (generate) and the CSV-streaming export
 * routes use the negative-only {@see assertForbiddenForNonAdmins}: generate's
 * success path mutates data (covered by the golden workflow) and export returns
 * a CSV stream rather than the JSON envelope the matrix helper decodes — its
 * admin-success path is asserted separately below.
 */
#[Group('security')]
final class AdminRegistrationCodePermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/registration-codes';

    public function testListEnforcesAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', self::BASE);
    }

    public function testGenerateIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins(
            'POST',
            self::BASE . '/generate',
            ['count' => 1, 'group_ids' => [1]],
        );
    }

    public function testExportIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('GET', self::BASE . '/export');
    }

    public function testExportReturnsCsvForAdmin(): void
    {
        $this->client->request(
            'GET',
            self::BASE . '/export',
            [],
            [],
            $this->authHeaders($this->loginAsQaAdmin()),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        // The functional client drains the StreamedResponse into the BrowserKit
        // internal response, so read the captured body from there. The CSV
        // header row is always present, even with zero data rows.
        self::assertStringContainsString('code', (string) $this->client->getInternalResponse()->getContent());
    }
}
