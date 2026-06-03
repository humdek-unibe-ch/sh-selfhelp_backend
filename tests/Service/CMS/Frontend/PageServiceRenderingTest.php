<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS\Frontend;

use App\Entity\Page;
use App\Exception\ServiceException;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-level coverage for {@see PageService} rendering of the stable,
 * guest-accessible seeded `home` page (the baseline reset grants the guest
 * group ACL select on it, so it renders with no security context):
 *   - keyword lookup matches id lookup (same hydrated page),
 *   - the hydrated draft payload shape (page + sections),
 *   - language resolution falls back to the CMS default when none is given,
 *   - the cross-platform mode guard 404s a `web`-only page for a mobile caller.
 *
 * Recursive section ordering / data_config hydration on a *controlled* page
 * graph is covered by the golden workflow
 * {@see \App\Tests\Golden\PublicPageRenderingWorkflowTest} (which grants ACL and
 * renders through HTTP).
 */
final class PageServiceRenderingTest extends QaKernelTestCase
{
    private PageService $pageService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pageService = $this->service(PageService::class);
    }

    public function testKeywordLookupMatchesIdLookup(): void
    {
        $homeId = $this->pageIdByKeyword('home');

        $byId = $this->pageService->getPage($homeId, null, false, LookupService::PAGE_ACCESS_TYPES_WEB);
        $byKeyword = $this->pageService->getPageByKeyword('home', null, false, LookupService::PAGE_ACCESS_TYPES_WEB);

        self::assertSame($homeId, $this->pageId($byId));
        self::assertSame($homeId, $this->pageId($byKeyword));
        self::assertSame('home', $this->pageKeyword($byId));
        self::assertSame($this->pageId($byId), $this->pageId($byKeyword), 'keyword and id lookups must resolve the same page.');
    }

    public function testHydratedPagePayloadHasPageAndSections(): void
    {
        $homeId = $this->pageIdByKeyword('home');

        $payload = $this->pageService->getPage($homeId, null, false, LookupService::PAGE_ACCESS_TYPES_WEB);

        self::assertArrayHasKey('page', $payload);
        self::assertIsArray($payload['page']);
        self::assertArrayHasKey('id', $payload['page']);
        self::assertArrayHasKey('keyword', $payload['page']);
        self::assertArrayHasKey('sections', $payload['page']);
        self::assertIsArray($payload['page']['sections'], 'Rendered page must carry a sections array.');
    }

    public function testLanguageDefaultsWhenNoneProvided(): void
    {
        $homeId = $this->pageIdByKeyword('home');

        // Passing null language must resolve to the CMS default (no exception)
        // and produce the same page as the explicit web mode call above.
        $payload = $this->pageService->getPage($homeId, null, false, LookupService::PAGE_ACCESS_TYPES_WEB);

        self::assertSame($homeId, $this->pageId($payload));
    }

    public function testWebOnlyPageRejectedForMobileMode(): void
    {
        $webOnlyId = $this->pageIdByKeyword('sh-global-css');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_NOT_FOUND);
        $this->pageService->getPage($webOnlyId, null, false, LookupService::PAGE_ACCESS_TYPES_MOBILE);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    private function pageId(array $payload): int
    {
        self::assertArrayHasKey('page', $payload);
        self::assertIsArray($payload['page']);

        return $this->coerceInt($payload['page']['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pageKeyword(array $payload): string
    {
        self::assertIsArray($payload['page']);

        return $this->coerceString($payload['page']['keyword'] ?? '');
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
