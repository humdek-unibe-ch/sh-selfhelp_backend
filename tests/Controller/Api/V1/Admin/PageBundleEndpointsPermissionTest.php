<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission-matrix coverage for the CMS-in-CMS page bundle and CMS-app admin
 * endpoints (Testing Rules 3 & 26). Each admin route must reject
 * unauthenticated callers (401) and authenticated non-admins (403) BEFORE any
 * body validation runs, so existence/behaviour is never leaked to a caller
 * without the admin permission.
 *
 * The public resolver (`GET /pages/resolve`) is intentionally NOT in this
 * matrix: it carries no route permission by design (it is the open-access
 * public-path lookup) and its own existence-leak guard is covered by
 * {@see \App\Tests\Controller\Api\V1\Frontend\PageResolvePublicPathTest}.
 */
#[Group('security')]
final class PageBundleEndpointsPermissionTest extends QaWebTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function adminEndpointProvider(): iterable
    {
        yield 'export' => ['POST', '/cms-api/v1/admin/pages/export', ['pageIds' => [1]]];
        yield 'examples' => ['GET', '/cms-api/v1/admin/pages/examples', null];
        yield 'export-suggest' => ['GET', '/cms-api/v1/admin/pages/1/export/suggest', null];
        yield 'import-validate' => ['POST', '/cms-api/v1/admin/pages/import/validate', ['bundle' => ['pages' => []]]];
        yield 'import' => ['POST', '/cms-api/v1/admin/pages/import', ['bundle' => ['pages' => []]]];
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
    #[DataProvider('adminEndpointProvider')]
    public function testUnauthenticatedIsRejected(string $method, string $uri, ?array $body): void
    {
        $envelope = $this->jsonRequest($method, $uri, $body, null);

        $this->assertEnvelope401($envelope);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminEndpointProvider')]
    public function testNonAdminIsForbidden(string $method, string $uri, ?array $body): void
    {
        $user = $this->loginAsQaUser();

        $envelope = $this->jsonRequest($method, $uri, $body, $user);

        $this->assertEnvelope403($envelope);
    }
}
