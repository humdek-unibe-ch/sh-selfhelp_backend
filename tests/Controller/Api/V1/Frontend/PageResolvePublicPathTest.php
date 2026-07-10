<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller coverage for the DB-driven public-path resolver endpoint
 * (`GET /cms-api/v1/pages/resolve`, issue #30).
 *
 * Asserts the observable contract: a missing `path` is a 400; an unknown path
 * is a 404; a real path resolves (200) and, in preview mode, carries the
 * no-store cache headers that keep an unpublished draft out of shared caches.
 * The probe page is created open-access through the admin API and deleted in a
 * finally block; DAMA rolls back the surrounding transaction.
 */
#[Group('golden')]
final class PageResolvePublicPathTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_resolve_probe';
    private const URL = '/qa-resolve-probe';

    public function testMissingPathParameterIsBadRequest(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/pages/resolve', null);

        $this->assertEnvelope400($envelope);
    }

    public function testUnknownPathIsNotFound(): void
    {
        $admin = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/resolve?path=' . rawurlencode('/qa-no-such-path-xyz-123') . '&preview=true',
            null,
            $admin
        );

        $this->assertEnvelope404($envelope);
    }

    public function testResolvablePathReturnsMetadataAndPreviewIsNoStore(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => 'web',
            'openAccess' => true,
            'url' => self::URL,
            'surface' => 'public',
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, 201);
        self::assertIsInt($pageData['id'] ?? null);
        $pageId = (int) $pageData['id'];

        try {
            // Create already persists a canonical active route from `url`
            // (AdminPageService auto-sync). Re-PUTting the same route is
            // unnecessary and historically raced the EntityManager in tests.

            // Preview mode (authenticated) resolves the draft and must be no-store.
            $this->client->request(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::URL) . '&preview=true',
                [],
                [],
                $this->authHeaders($admin)
            );
            $response = $this->client->getResponse();
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

            $cacheControl = (string) $response->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cacheControl, 'Preview resolve must be no-store.');
            self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        }
    }
}
