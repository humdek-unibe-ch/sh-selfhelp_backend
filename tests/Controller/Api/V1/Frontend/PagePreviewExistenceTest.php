<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A preview must not reveal a page the caller cannot see (rule 4.3, F152).
 * `preview=true` answers exactly what the same request without preview answers
 * for an unseen page; the edit-rights gate only applies on a page the caller
 * can already read.
 */
#[Group('golden')]
#[Group('security')]
final class PagePreviewExistenceTest extends QaWebTestCase
{
    private const CMS_KEYWORD = 'qa_preview_exist_cms';
    private const CMS_URL = '/cms/qa-preview-exist-cms';
    private const PUBLIC_KEYWORD = 'qa_preview_exist_public';
    private const PUBLIC_URL = '/qa-preview-exist-public';

    public function testParticipantPreviewOfAnUnseenPageMatchesWithoutPreview(): void
    {
        $admin = $this->loginAsQaAdmin();

        $cms = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::CMS_KEYWORD,
            'pageAccessTypeCode' => 'web',
            'openAccess' => true,
            'url' => self::CMS_URL,
            'surface' => 'cms',
        ], $admin);
        $cmsId = self::coerceInt($this->assertEnvelopeSuccess($cms, 201)['id'] ?? null);

        $public = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::PUBLIC_KEYWORD,
            'pageAccessTypeCode' => 'web',
            'openAccess' => false,
            'url' => self::PUBLIC_URL,
            'surface' => 'public',
        ], $admin);
        $publicId = self::coerceInt($this->assertEnvelopeSuccess($public, 201)['id'] ?? null);

        // Drop every group grant so qa.user cannot see the ordinary page.
        $em = $this->service(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            'DELETE FROM page_acl_groups WHERE id_pages = :page',
            ['page' => $publicId],
        );
        $cache = $this->service(CacheService::class);
        self::assertInstanceOf(CacheService::class, $cache);
        foreach ([CacheService::CATEGORY_PAGES, CacheService::CATEGORY_PERMISSIONS] as $category) {
            $cache->withCategory($category)->invalidateCategory();
        }

        try {
            $user = $this->loginAsQaUser();

            $missingWithout = $this->statusOf('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode('/cms/qa-preview-exist-missing'), $user);
            $missingWith = $this->statusOf('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode('/cms/qa-preview-exist-missing') . '&preview=true', $user);
            self::assertSame(404, $missingWithout);
            self::assertSame($missingWithout, $missingWith, 'A missing path with preview must match without preview.');

            foreach ([
                ['path', '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::CMS_URL)],
                ['keyword', '/cms-api/v1/pages/by-keyword/' . self::CMS_KEYWORD],
            ] as [$label, $uri]) {
                $without = $this->statusOf('GET', $uri, $user);
                $with = $this->statusOf('GET', $uri . (str_contains($uri, '?') ? '&' : '?') . 'preview=true', $user);
                self::assertSame(404, $without, 'CMS-surface unseen ' . $label . ' without preview');
                self::assertSame($without, $with, 'CMS-surface unseen ' . $label . ' with preview must match without preview');
            }

            foreach ([
                ['path', '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::PUBLIC_URL)],
                ['keyword', '/cms-api/v1/pages/by-keyword/' . self::PUBLIC_KEYWORD],
            ] as [$label, $uri]) {
                $without = $this->statusOf('GET', $uri, $user);
                $with = $this->statusOf('GET', $uri . (str_contains($uri, '?') ? '&' : '?') . 'preview=true', $user);
                self::assertSame($without, $with, 'Ordinary unseen ' . $label . ' with preview must match without preview');
                self::assertContains($without, [403, 404], 'Ordinary unseen ' . $label . ' is refused');
            }
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $cmsId), null, $admin);
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $publicId), null, $admin);
        }
    }

    private function statusOf(string $method, string $uri, string $token): int
    {
        $response = $this->jsonRequest($method, $uri, null, $token);

        return self::coerceInt($response['status'] ?? null);
    }
}
