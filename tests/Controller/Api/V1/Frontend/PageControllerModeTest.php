<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Frontend\PageController}:
 * the public page list / by-keyword endpoints, platform-mode resolution
 * (header > query > legacy flag), published-page rendering, the cross-platform
 * 404 guard, and the preview no-cache headers + preview auth gate.
 *
 * Page content is resolved exclusively by keyword (the legacy numeric-id route
 * was removed). The routes are seeded permission-less (public), so the read
 * paths run as a guest; preview runs as qa.admin (it needs auth + page ACL
 * select). Pages used are the stable legacy-seeded ones (`home`, `sh-global-css`)
 * loaded by the baseline reset, addressed by keyword.
 */
final class PageControllerModeTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/pages';

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    // -- list endpoint + mode resolution -----------------------------------

    public function testListPagesReturnsAclPageDefinitionEnvelopeForGuest(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE);

        $this->assertEnvelopeSuccess($envelope);
        $this->assertResponseMatchesSchema('responses/common/_acl_page_definition');
    }

    public function testListPagesAcceptsClientTypeHeader(): void
    {
        $this->guestRequest('GET', self::BASE, ['HTTP_X_CLIENT_TYPE' => 'mobile']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertResponseMatchesSchema('responses/common/_acl_page_definition');
    }

    public function testListPagesAcceptsPlatformQuery(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '?platform=mobile');
        $this->assertEnvelopeSuccess($envelope);
    }

    public function testListPagesAcceptsLegacyMobileFlag(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '?mobile=1');
        $this->assertEnvelopeSuccess($envelope);
    }

    // -- single page (by keyword) ------------------------------------------

    public function testGetPageByKeywordReturnsPageEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '/by-keyword/home');

        $this->assertEnvelopeSuccess($envelope);
        $this->assertResponseMatchesSchema('responses/frontend/get_page');
    }

    public function testWebOnlyPageRequestedAsMobileReturns404(): void
    {
        // sh-global-css is a `web`-only page; the mode guard rejects a mobile
        // caller with 404 (no metadata leak) before any ACL check.
        $this->guestRequest('GET', self::BASE . '/by-keyword/sh-global-css', ['HTTP_X_CLIENT_TYPE' => 'mobile']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownKeywordReturns404(): void
    {
        $this->assertEnvelope404($this->jsonRequest('GET', self::BASE . '/by-keyword/qa_missing_keyword'));
    }

    // -- preview no-cache headers + auth gate ------------------------------

    public function testPreviewSetsNoCacheHeaders(): void
    {
        $token = $this->loginAsQaAdmin();

        $this->client->request(
            'GET',
            self::BASE . '/by-keyword/home?preview=true',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function testPreviewRequiresAuthentication(): void
    {
        // Drafts must never be served anonymously: an unauthenticated preview
        // request is rejected with 401 before any draft is rendered.
        $envelope = $this->jsonRequest('GET', self::BASE . '/by-keyword/home?preview=true');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $envelope['status'] ?? null, 'Anonymous preview must be unauthorized.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, string> $server
     */
    private function guestRequest(string $method, string $uri, array $server = []): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'] + $server);
    }

    private function assertResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }
}
