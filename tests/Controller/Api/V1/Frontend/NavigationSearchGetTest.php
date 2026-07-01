<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class NavigationSearchGetTest extends QaWebTestCase
{
    public function testSearchReturnsEmptyForShortQuery(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/search?query=a&language_id=1');
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsArray($data['results'] ?? null);
        self::assertSame([], $data['results']);
    }

    public function testSearchPagesEndpointReturnsArray(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/search/pages?query=home&language_id=1');
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertIsArray($data['results'] ?? null);
    }

    public function testNavigationStartupIncludesLastVisitedKeys(): void
    {
        $user = $this->loginAsQaAdmin();
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/navigation?language_id=1', null, $user);
        $data = $this->assertEnvelopeSuccess($envelope);
        $startup = $data['startup'] ?? null;
        self::assertIsArray($startup);
        self::assertArrayHasKey('web_user_last_visited_page', $startup);
        self::assertArrayHasKey('mobile_user_last_visited_page', $startup);
    }
}
