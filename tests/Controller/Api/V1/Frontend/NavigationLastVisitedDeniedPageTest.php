<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationLastVisitedDeniedPageTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_last_visited_denied';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => self::KEYWORD]);
        if ($page instanceof Page) {
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        }

        parent::tearDown();
    }

    public function testStartupOmitsLastVisitedWhenPageIsDeleted(): void
    {
        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $put = $this->jsonRequest('PUT', '/cms-api/v1/navigation/last-visited', [
            'page_id' => $pageId,
            'keyword' => self::KEYWORD,
            'url' => '/' . self::KEYWORD,
        ], $admin, ['HTTP_X-Client-Type' => 'web']);
        $this->assertEnvelopeSuccess($put, Response::HTTP_OK);

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId, null, $admin);

        $navigation = $this->jsonRequest('GET', '/cms-api/v1/navigation?language_id=1', null, $admin);
        $payload = $this->assertEnvelopeSuccess($navigation);
        $startup = $payload['startup'] ?? null;
        self::assertIsArray($startup);
        self::assertNull($startup['web_user_last_visited_page'] ?? null);
    }
}
