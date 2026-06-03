<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Page;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Frontend\PageController}:
 * the public page list / by-id / by-keyword endpoints, platform-mode resolution
 * (header > query > legacy flag), published-page rendering, the cross-platform
 * 404 guard, and the preview no-cache headers.
 *
 * All four routes are seeded permission-less (public), so the success paths run
 * as a guest; preview runs as qa.admin (it needs page ACL select). Pages used
 * are the stable legacy-seeded ones (`home`, `sh-global-css`) loaded by the
 * baseline reset, addressed by keyword so the test never hard-codes ids.
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

    // -- single page (by id / keyword) -------------------------------------

    public function testGetOpenPageByIdReturnsPageEnvelope(): void
    {
        $homeId = $this->pageIdByKeyword('home');

        $envelope = $this->jsonRequest('GET', self::BASE . '/' . $homeId);

        $this->assertEnvelopeSuccess($envelope);
        $this->assertResponseMatchesSchema('responses/frontend/get_page');
    }

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
        $webOnlyId = $this->pageIdByKeyword('sh-global-css');

        $this->guestRequest('GET', self::BASE . '/' . $webOnlyId, ['HTTP_X_CLIENT_TYPE' => 'mobile']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownPageIdReturns404(): void
    {
        $this->assertEnvelope404($this->jsonRequest('GET', self::BASE . '/99000111'));
    }

    public function testUnknownKeywordReturns404(): void
    {
        $this->assertEnvelope404($this->jsonRequest('GET', self::BASE . '/by-keyword/qa_missing_keyword'));
    }

    // -- preview no-cache headers ------------------------------------------

    public function testPreviewSetsNoCacheHeaders(): void
    {
        $token = $this->loginAsQaAdmin();
        $homeId = $this->pageIdByKeyword('home');

        $this->client->request(
            'GET',
            self::BASE . '/' . $homeId . '?preview=true',
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

    private function pageIdByKeyword(string $keyword): int
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->getRepository(Page::class)->findOneBy(['keyword' => $keyword]);
        self::assertInstanceOf(Page::class, $page, "Seeded page '{$keyword}' missing. Run: composer test:reset-db");

        return (int) $page->getId();
    }
}
