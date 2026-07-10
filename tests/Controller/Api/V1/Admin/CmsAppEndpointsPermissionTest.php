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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-matrix + legacy-route regression for first-class CMS apps.
 *
 * QA personas: only qa.admin holds the `admin` role with `admin.cms_app.*`.
 * qa.editor is in the therapist group with no admin role, so it must be
 * rejected on every `/admin/cms-apps*` route even if it could edit content
 * elsewhere. That is the cross-scope negative case for CMS-app permissions.
 */
#[Group('security')]
final class CmsAppEndpointsPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function cmsAppEndpointProvider(): iterable
    {
        yield 'cms-apps-list' => ['GET', '/cms-api/v1/admin/cms-apps', null];
        yield 'cms-apps-create' => ['POST', '/cms-api/v1/admin/cms-apps', ['name' => 'qa-perm-probe', 'slug' => 'qa-perm-probe']];
        yield 'cms-apps-get' => ['GET', '/cms-api/v1/admin/cms-apps/1', null];
        yield 'cms-apps-by-slug' => ['GET', '/cms-api/v1/admin/cms-apps/by-slug/qa-perm-probe', null];
        yield 'cms-apps-update' => ['PATCH', '/cms-api/v1/admin/cms-apps/1', ['name' => 'qa-perm-probe']];
        yield 'cms-apps-delete' => ['DELETE', '/cms-api/v1/admin/cms-apps/1', null];
        yield 'cms-apps-assign' => ['POST', '/cms-api/v1/admin/cms-apps/1/pages', ['page_id' => 1, 'role' => 'other']];
        yield 'cms-apps-change-role' => ['PATCH', '/cms-api/v1/admin/cms-apps/1/pages/1', ['role' => 'other']];
        yield 'cms-apps-unassign' => ['DELETE', '/cms-api/v1/admin/cms-apps/1/pages/1', null];
        yield 'cms-apps-scaffold' => ['POST', '/cms-api/v1/admin/cms-apps/1/scaffold', ['base_name' => 'qa-perm-probe']];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('cmsAppEndpointProvider')]
    public function testEditorIsForbiddenWithoutCmsAppPermissions(string $method, string $uri, ?array $body): void
    {
        $editor = $this->loginAsQaEditor();

        $envelope = $this->jsonRequest($method, $uri, $body, $editor);

        $this->assertEnvelope403($envelope);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('cmsAppEndpointProvider')]
    public function testForbiddenForNonAdmins(string $method, string $uri, ?array $body): void
    {
        $this->assertForbiddenForNonAdmins($method, $uri, $body);
    }

    public function testLegacyCreateCmsAppWizardRouteIsRemoved(): void
    {
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/pages/cms-app',
            ['base_name' => 'qa-legacy-probe'],
            $admin
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $envelope['status'] ?? null,
            sprintf(
                'Legacy POST /admin/pages/cms-app must be absent after Version20260710093044 (got %s)',
                var_export($envelope['status'] ?? null, true)
            )
        );
    }

    public function testQaAdminCanListCmsApps(): void
    {
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/cms-apps', null, $admin);

        self::assertSame(
            Response::HTTP_OK,
            $envelope['status'] ?? null,
            sprintf('qa.admin (%s) must list CMS apps', QaBaselineFixture::QA_ADMIN_EMAIL)
        );
    }
}
