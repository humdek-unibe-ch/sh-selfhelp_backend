<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Access;

use App\Service\Access\AccessCatalogueRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The generated access files (F239, rule 4.14): byte-stable output, the
 * catalogue lists routes and screens per permission, and a permission that
 * guards no route and opens no screen is reported.
 *
 * @phpstan-import-type AccessRouteTable from AccessCatalogueRenderer
 * @phpstan-import-type AdminScreen from AccessCatalogueRenderer
 */
final class AccessCatalogueRendererTest extends TestCase
{
    private AccessCatalogueRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AccessCatalogueRenderer();
    }

    /**
     * @return AccessRouteTable
     */
    private function table(): array
    {
        return $this->renderer->buildRouteTable(
            [
                ['name' => 'z_route', 'methods' => ['put', 'PATCH'], 'path' => '/admin/pages/{page_id}', 'is_public' => false, 'permissions' => ['admin.page.update']],
                ['name' => 'a_route', 'methods' => ['GET'], 'path' => '/admin/pages/{page_id}', 'is_public' => false, 'permissions' => ['admin.page.read', 'admin.access']],
                ['name' => 'health', 'methods' => ['GET'], 'path' => '/health', 'is_public' => true, 'permissions' => []],
            ],
            [
                ['name' => 'admin.page.update', 'description' => 'Edit pages'],
                ['name' => 'admin.page.read', 'description' => 'See pages'],
                ['name' => 'admin.mobile_preview.read', 'description' => 'Open the mobile preview'],
                ['name' => 'admin.unused.thing', 'description' => ''],
                ['name' => 'admin.access', 'description' => 'Open the admin'],
            ]
        );
    }

    /**
     * @return list<AdminScreen>
     */
    private function screens(): array
    {
        return [
            ['id' => 'preview', 'label' => 'Mobile Preview', 'path' => '/admin/preview', 'group' => 'system', 'read_permission' => 'admin.mobile_preview.read'],
            ['id' => 'pages', 'label' => 'Pages', 'path' => '/admin/pages', 'group' => 'pages', 'read_permission' => 'admin.page.read'],
        ];
    }

    public function testRouteTableIsSortedAndNormalised(): void
    {
        $table = $this->table();

        self::assertSame(AccessCatalogueRenderer::ROUTES_GENERATED_BY, $table['generated_by']);
        self::assertSame(['a_route', 'z_route', 'health'], array_column($table['routes'], 'name'), 'sorted by path, then name');
        self::assertSame(['PATCH', 'PUT'], $table['routes'][1]['methods'], 'methods upper case and sorted');
        self::assertSame(['admin.access', 'admin.page.read'], $table['routes'][0]['permissions']);
        self::assertSame(
            ['admin.access', 'admin.mobile_preview.read', 'admin.page.read', 'admin.page.update', 'admin.unused.thing'],
            array_column($table['permissions'], 'name')
        );
    }

    public function testRouteTableJsonUsesTwoSpacesAndATrailingNewline(): void
    {
        $json = $this->renderer->renderRouteTableJson($this->table());

        self::assertStringEndsWith("}\n", $json);
        self::assertStringNotContainsString("\r", $json);
        self::assertStringContainsString("\n  \"routes\": [\n    {\n      \"name\": \"a_route\",", $json);
        self::assertStringContainsString('"path": "/admin/pages/{page_id}"', $json, 'slashes are not escaped');
        self::assertStringContainsString('"permissions": []', $json, 'a public route keeps an empty list');
        self::assertStringContainsString('"is_public": true', $json);
        self::assertSame($json, $this->renderer->renderRouteTableJson($this->table()), 'byte-stable');
    }

    public function testCatalogueListsRoutesAndScreensPerPermission(): void
    {
        $md = $this->renderer->renderCatalogue($this->table(), $this->screens(), '9.9.9');

        self::assertStringStartsWith("# Permission catalogue\n\nAudience:", $md);
        self::assertStringContainsString('Applies to: SelfHelp2 core 9.9.9.', $md);
        self::assertStringContainsString("## `admin.page.read`\n\nSee pages\n\nRoutes:\n\n- `GET /admin/pages/{page_id}`\n\nScreens:\n\n- Pages (`/admin/pages`)\n", $md);
        self::assertStringContainsString("## `admin.page.update`\n\nEdit pages\n\nRoutes:\n\n- `PATCH,PUT /admin/pages/{page_id}`\n\nScreens:\n\n- none\n", $md, 'route only');
        self::assertStringContainsString("## `admin.mobile_preview.read`\n\nOpen the mobile preview\n\nRoutes:\n\n- none\n\nScreens:\n\n- Mobile Preview (`/admin/preview`)\n", $md, 'screen only');
        self::assertStringContainsString("## `admin.unused.thing`\n\n(no description)\n", $md);
        self::assertStringNotContainsString('/health', $md, 'public routes have no permission section');
        self::assertStringEndsWith("\n", $md);
        self::assertSame($md, $this->renderer->renderCatalogue($this->table(), array_reverse($this->screens()), '9.9.9'), 'byte-stable whatever the screen order');
    }

    public function testPermissionThatGuardsNothingIsReported(): void
    {
        self::assertSame(['admin.unused.thing'], $this->renderer->findUnguardedPermissions($this->table(), $this->screens()));
        self::assertSame(
            ['admin.mobile_preview.read', 'admin.unused.thing'],
            $this->renderer->findUnguardedPermissions($this->table(), []),
            'without its screen, a screen-only permission guards nothing'
        );
    }

    public function testScreensFileMustHaveTheAgreedShape(): void
    {
        $screens = $this->renderer->parseScreens(
            '{"generated_by":"npm run access:screens","screens":[{"id":"pages","label":"Pages","path":"/admin/pages","group":"pages","read_permission":"admin.page.read"}]}',
            'x.json'
        );
        self::assertSame('admin.page.read', $screens[0]['read_permission']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no "read_permission"');
        $this->renderer->parseScreens('{"screens":[{"id":"pages","label":"Pages","path":"/admin/pages","group":"pages"}]}', 'x.json');
    }

    public function testScreensFileThatIsNotJsonIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->renderer->parseScreens('{', 'x.json');
    }
}
