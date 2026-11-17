<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin can set per-menu root item_limit; 0/negative are refused; null clears.
 */
#[Group('integration')]
final class AdminNavigationMenuItemLimitTest extends QaWebTestCase
{
    public function testSetReadAndClearItemLimit(): void
    {
        $admin = $this->loginAsQaAdmin();

        $updated = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/web_header',
            ['item_limit' => 12],
            $admin,
        );
        $data = $this->assertEnvelopeSuccess($updated);
        self::assertSame(12, $data['item_limit'] ?? null);

        $cleared = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/web_header',
            ['item_limit' => null],
            $admin,
        );
        $clearedData = $this->assertEnvelopeSuccess($cleared);
        self::assertArrayHasKey('item_limit', $clearedData);
        self::assertNull($clearedData['item_limit']);

        // Restore a finite default so other tests that assume a capped header stay stable.
        $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/web_header',
            ['item_limit' => 8],
            $admin,
        );
    }

    public function testZeroItemLimitIsRejected(): void
    {
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/navigation/menus/web_header',
            ['item_limit' => 0],
            $admin,
        );
        $this->assertEnvelope400($envelope);
    }
}
