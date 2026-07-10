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
 * CMS-surface pages (`page_surface=cms`) must not leak through public
 * keyword/path resolve to anonymous or normal authenticated users. Host Admin
 * callers with admin page select may resolve them (CMS Apps content host /
 * authorized preview).
 *
 * Create already persists a canonical `page_routes` row from `url`, so this
 * test does not re-PUT routes (avoids redundant sync).
 */
#[Group('golden')]
final class PageCmsSurfaceAccessTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_cms_surface_probe';
    private const URL = '/cms/qa-cms-surface-probe';

    public function testCmsSurfaceRejectedForAnonymousAndNormalUserButAllowedForAdmin(): void
    {
        $admin = $this->loginAsQaAdmin();

        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => 'web',
            'openAccess' => true,
            'url' => self::URL,
            'surface' => 'cms',
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, 201);
        $pageId = self::coerceInt($pageData['id'] ?? null);

        try {
            $anonPath = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::URL),
                null
            );
            $this->assertEnvelope404($anonPath);

            $anonKeyword = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/by-keyword/' . rawurlencode(self::KEYWORD),
                null
            );
            $this->assertEnvelope404($anonKeyword);

            $user = $this->loginAsQaUser();
            $userPath = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::URL),
                null,
                $user
            );
            $this->assertEnvelope404($userPath);

            $userKeyword = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/by-keyword/' . rawurlencode(self::KEYWORD),
                null,
                $user
            );
            $this->assertEnvelope404($userKeyword);

            $adminPath = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::URL) . '&preview=true',
                null,
                $admin
            );
            $adminPathData = $this->assertEnvelopeSuccess($adminPath);
            $adminPathPage = self::asArray($adminPathData['page'] ?? null);
            self::assertSame(self::KEYWORD, $adminPathPage['keyword'] ?? null);
            self::assertSame('cms', $adminPathPage['page_surface'] ?? null);

            $adminKeyword = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/by-keyword/' . rawurlencode(self::KEYWORD) . '?preview=true',
                null,
                $admin
            );
            $adminKeywordData = $this->assertEnvelopeSuccess($adminKeyword);
            $adminKeywordPage = self::asArray($adminKeywordData['page'] ?? null);
            self::assertSame(self::KEYWORD, $adminKeywordPage['keyword'] ?? null);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        }
    }
}
