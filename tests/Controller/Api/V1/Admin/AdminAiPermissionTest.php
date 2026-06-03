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
 * Permission matrix for the admin AI helper API (plan §29).
 *
 * `GET /admin/ai/section-prompt-template`
 * (AdminStyleController::getSectionPromptTemplate) is guarded by
 * `admin.page.export`. The QA baseline grants that permission only to the admin
 * role (qa.admin).
 *
 * Unlike the other admin routes this endpoint returns a raw `text/markdown`
 * body (the prompt template) rather than the JSON envelope, so the admin-allowed
 * case is asserted on the raw HTTP status while the denials (which are produced
 * by the security listener as JSON envelopes) reuse the shared matrix helper.
 */
#[Group('security')]
final class AdminAiPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testSectionPromptTemplateIsAllowedForAdmin(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/ai/section-prompt-template',
            [],
            [],
            $this->authHeaders($this->loginAsQaAdmin()),
        );

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            'qa.admin holds admin.page.export and must be allowed to read the section prompt template.',
        );
    }

    public function testSectionPromptTemplateIsForbiddenForNonAdmins(): void
    {
        // Non-admins (403) and anonymous (401) are rejected by the security
        // listener before the controller runs — those responses are JSON
        // envelopes, so the shared negative-matrix helper applies.
        $this->assertForbiddenForNonAdmins('GET', '/cms-api/v1/admin/ai/section-prompt-template');
    }
}
