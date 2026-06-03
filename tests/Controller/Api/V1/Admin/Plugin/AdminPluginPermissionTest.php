<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Permission matrix for the core plugin-admin routes
 * (`/cms-api/v1/admin/plugins/...`), all guarded by `admin.plugins.manage`
 * (reads) or `admin.plugins.execute` / `admin.plugins.purge` (writes).
 *
 * Read routes assert the full admin-only matrix (admin 200, non-admins 403,
 * anonymous 401). Write/destructive routes assert only the negative half so the
 * matrix never mutates plugin state or triggers a registry fetch / Composer run
 * — the success path is owned by the plugin lifecycle tests
 * ({@see ManagedModeInstallTest}).
 */
#[Group('security')]
final class AdminPluginPermissionTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    /**
     * QA-prefixed source URL for the create-source permission case. The body is
     * never processed (the request is rejected by the permission layer before
     * validation), but referencing a constant keeps the QA-data guard green
     * instead of an inline non-QA url literal.
     */
    private const QA_SOURCE_URL = 'https://qa-plugins.selfhelp.test/registry/';

    /**
     * Local DB-read routes are safe to exercise as qa.admin (no outbound).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function adminReadRoutes(): array
    {
        return [
            ['GET', '/cms-api/v1/admin/plugins'],
            ['GET', '/cms-api/v1/admin/plugins/sources'],
            ['GET', '/cms-api/v1/admin/plugins/operations'],
            ['GET', '/cms-api/v1/admin/plugins/doctor'],
        ];
    }

    /**
     * Write/destructive routes — negative matrix only (no admin success call).
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function adminWriteRoutes(): array
    {
        return [
            ['POST', '/cms-api/v1/admin/plugins/install', ['source' => 'paste', 'manifest' => '{}']],
            ['POST', '/cms-api/v1/admin/plugins/qa-nonexistent/enable', null],
            ['POST', '/cms-api/v1/admin/plugins/qa-nonexistent/disable', null],
            ['POST', '/cms-api/v1/admin/plugins/qa-nonexistent/uninstall', null],
            ['POST', '/cms-api/v1/admin/plugins/qa-nonexistent/purge', ['confirm' => 'qa-nonexistent']],
            ['POST', '/cms-api/v1/admin/plugins/sources', ['name' => 'qa-src', 'url' => self::QA_SOURCE_URL]],
        ];
    }

    #[DataProvider('adminReadRoutes')]
    public function testPluginAdminReadRoutesAreAdminOnly(string $method, string $uri): void
    {
        $this->assertAdminOnlyMatrix($method, $uri);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('adminWriteRoutes')]
    public function testPluginAdminWriteRoutesRejectNonAdmins(string $method, string $uri, ?array $body): void
    {
        $this->assertForbiddenForNonAdmins($method, $uri, $body);
    }
}
