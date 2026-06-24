<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Service\MobilePreview\MobilePreviewSessionService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * End-to-end behaviour of the CMS mobile-preview session flow:
 *
 *   1. qa.admin mints a one-time code (admin route).
 *   2. The public exchange route consumes the code and returns a scoped JWT.
 *   3. The scoped JWT can read an allowlisted route (languages).
 *   4. The scoped JWT is REJECTED (403) on a non-allowlisted admin route, even
 *      though it carries the admin's identity — proving the guard scopes it.
 *   5. The code is single-use: re-exchanging it fails 401.
 *
 * Reboot is disabled so the minted code (stored in the app cache) survives into
 * the exchange request, mirroring the multi-request golden tests.
 */
#[Group('integration')]
final class MobilePreviewSessionTest extends QaWebTestCase
{
    private const MINT = '/cms-api/v1/admin/mobile-preview/session';
    private const EXCHANGE = '/cms-api/v1/mobile-preview/session/exchange';

    public function testPreviewSessionMintExchangeAndScopeEnforcement(): void
    {
        $this->client->disableReboot();

        // 1. Mint (admin only).
        $admin = $this->loginAsQaAdmin();
        $mint = $this->jsonRequest('POST', self::MINT, ['draft' => false], $admin);
        $mintData = $this->assertEnvelopeSuccess($mint);
        self::assertArrayHasKey('code', $mintData);
        self::assertArrayHasKey('expires_at', $mintData);
        $code = $mintData['code'];
        self::assertIsString($code);
        self::assertNotSame('', $code);

        // 2. Exchange (PUBLIC: no token — the code is the credential).
        $exchange = $this->jsonRequest('POST', self::EXCHANGE, ['code' => $code], null);
        $exchangeData = $this->assertEnvelopeSuccess($exchange);
        self::assertArrayHasKey('access_token', $exchangeData);
        self::assertArrayHasKey('expires_in', $exchangeData);
        self::assertArrayHasKey('user', $exchangeData);
        $previewToken = $exchangeData['access_token'];
        self::assertIsString($previewToken);
        self::assertNotSame('', $previewToken);
        self::assertIsArray($exchangeData['user']);

        // 3. Allowlisted read succeeds with the scoped token.
        $languages = $this->jsonRequest('GET', '/cms-api/v1/languages', null, $previewToken);
        self::assertSame(Response::HTTP_OK, $languages['status'] ?? null, 'Preview token must read languages');

        // 4. Non-allowlisted admin route is blocked by the scope guard (403),
        //    even though the token carries the admin identity.
        $adminPages = $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $previewToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $adminPages['status'] ?? null,
            'Preview token must be denied on non-allowlisted admin routes',
        );

        // 5. Single-use: the same code cannot be exchanged twice.
        $replay = $this->jsonRequest('POST', self::EXCHANGE, ['code' => $code], null);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $replay['status'] ?? null,
            'A one-time preview code must be rejected on replay',
        );
    }

    public function testExchangeRejectsUnknownCode(): void
    {
        $this->client->disableReboot();

        $bogus = str_repeat('a', 64);
        $envelope = $this->jsonRequest('POST', self::EXCHANGE, ['code' => $bogus], null);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $envelope['status'] ?? null,
            'An unknown preview code must be rejected with 401',
        );
    }

    public function testExchangeRejectsExpiredCode(): void
    {
        $this->client->disableReboot();

        $admin = $this->loginAsQaAdmin();
        $mint = $this->jsonRequest('POST', self::MINT, ['keyword' => 'home', 'draft' => false], $admin);
        $mintData = $this->assertEnvelopeSuccess($mint);
        $code = $mintData['code'];
        self::assertIsString($code);

        $cache = $this->service(CacheInterface::class);
        $cache->delete(MobilePreviewSessionService::CODE_PREFIX . hash('sha256', $code));

        $expired = $this->jsonRequest('POST', self::EXCHANGE, ['code' => $code], null);
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $expired['status'] ?? null,
            'An expired preview code must be rejected with 401',
        );
    }

    public function testPreviewTokenCannotReadAnotherScopedPage(): void
    {
        $this->client->disableReboot();

        $admin = $this->loginAsQaAdmin();
        $mint = $this->jsonRequest('POST', self::MINT, ['keyword' => 'home', 'language_id' => 1, 'draft' => true], $admin);
        $mintData = $this->assertEnvelopeSuccess($mint);
        $code = $mintData['code'];
        self::assertIsString($code);

        $exchange = $this->jsonRequest('POST', self::EXCHANGE, ['code' => $code], null);
        $exchangeData = $this->assertEnvelopeSuccess($exchange);
        $previewToken = $exchangeData['access_token'];
        self::assertIsString($previewToken);

        $otherPage = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/maintenance?language_id=1&preview=true',
            null,
            $previewToken,
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $otherPage['status'] ?? null,
            'Preview token must be denied when it reads outside the minted keyword scope',
        );
    }
}
