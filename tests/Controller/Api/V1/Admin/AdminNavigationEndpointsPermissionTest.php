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

#[Group('security')]
final class AdminNavigationEndpointsPermissionTest extends QaWebTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function readEndpointProvider(): iterable
    {
        yield 'overview' => ['GET', '/cms-api/v1/admin/navigation', null];
        yield 'preview' => ['GET', '/cms-api/v1/admin/navigation/menus/web_header/preview', null];
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    public static function writeEndpointProvider(): iterable
    {
        yield 'menu-update' => ['PUT', '/cms-api/v1/admin/navigation/menus/web_header', ['max_depth' => 2]];
        yield 'item-create' => ['POST', '/cms-api/v1/admin/navigation/menus/web_header/items', ['page_id' => 1, 'position' => 999]];
        yield 'item-update' => ['PUT', '/cms-api/v1/admin/navigation/items/1', ['position' => 1]];
        yield 'item-delete' => ['DELETE', '/cms-api/v1/admin/navigation/items/1', null];
        yield 'reorder' => ['PUT', '/cms-api/v1/admin/navigation/menus/web_header/reorder', ['items' => []]];
        yield 'settings' => ['PUT', '/cms-api/v1/admin/navigation/settings', ['web_header_search_min_chars' => 2]];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('readEndpointProvider')]
    public function testReadUnauthenticatedIsRejected(string $method, string $uri, ?array $body): void
    {
        $envelope = $this->jsonRequest($method, $uri, $body, null);
        $this->assertEnvelope401($envelope);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('writeEndpointProvider')]
    public function testWriteUnauthenticatedIsRejected(string $method, string $uri, ?array $body): void
    {
        $envelope = $this->jsonRequest($method, $uri, $body, null);
        $this->assertEnvelope401($envelope);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('readEndpointProvider')]
    public function testReadNonAdminIsForbidden(string $method, string $uri, ?array $body): void
    {
        $user = $this->loginAsQaUser();
        $envelope = $this->jsonRequest($method, $uri, $body, $user);
        $this->assertEnvelope403($envelope);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('writeEndpointProvider')]
    public function testWriteNonAdminIsForbidden(string $method, string $uri, ?array $body): void
    {
        $user = $this->loginAsQaUser();
        $envelope = $this->jsonRequest($method, $uri, $body, $user);
        $this->assertEnvelope403($envelope);
    }

    public function testAdminCanReadNavigationOverview(): void
    {
        $admin = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/navigation', null, $admin);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsArray($data['menus'] ?? null);
        self::assertIsArray($data['settings'] ?? null);
    }

    public function testAdminCanPreviewWebHeaderMenu(): void
    {
        $admin = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/navigation/menus/web_header/preview?language_id=1',
            null,
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame('web_header', $data['menu_key'] ?? null);
    }
}
