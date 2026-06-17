<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Style;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden workflow for the shared-section (refContainer) lifecycle across pages.
 *
 * A single container section is authored on page A, then *reused* on page B —
 * the refContainer feature lets one section render in multiple places. The test
 * asserts the three guarantees the section-deletion refactor must keep:
 *
 *   1. Add-across-pages: the same section row is linked to a second page and
 *      appears on both pages' admin section trees.
 *   2. Bulk remove is detach-only: bulk-removing the shared section from page A
 *      unlinks it from A but keeps it on page B (the same outcome as the single
 *      "remove from page"), and the section row survives. Regression guard for
 *      "bulkRemoveSections destroyed nested sections while the single remove
 *      only detached".
 *   3. Destroy invalidates shared-page caches: permanently deleting the section
 *      removes it from BOTH pages' (cached) admin trees with no stale render.
 *      Regression guard for "deleteSection invalidated caches *after* the
 *      relationship rows were already gone, so other pages kept serving the
 *      deleted shared section".
 *
 * All rows are qa_-prefixed and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
final class RefContainerSharedSectionWorkflowTest extends QaWebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        // Share one kernel/cache pool across the requests so the page-scope
        // cache the writes invalidate is the exact pool the admin reads come
        // from (the proven SectionAuthoringRenderingWorkflowTest pattern). This
        // is what makes the "destroy invalidates the shared page cache"
        // assertion meaningful rather than trivially passing on a cold cache.
        $this->client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testSharedSectionReuseDetachAndDestroyAcrossPages(): void
    {
        $admin = $this->loginAsQaAdmin();
        $containerStyleId = $this->styleId('container');

        $pageA = $this->createPage('qa_refcontainer_page_a', '/qa-refcontainer-page-a', $admin);
        $pageB = $this->createPage('qa_refcontainer_page_b', '/qa-refcontainer-page-b', $admin);

        // Shared section authored on page A.
        $shared = $this->createPageSection($pageA, $containerStyleId, 10, 'qa_shared_refcontainer', $admin);

        // 1. Reuse the SAME section on page B (refContainer add-across-pages).
        //    The DB-backed route table is authoritative: add-existing-section is
        //    PUT /admin/pages/{id}/sections (the controller @method doc is stale).
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageB), ['sectionId' => $shared], $admin)
        );

        self::assertContains($shared, $this->pageSectionIds($pageA, $admin), 'Shared section must be on page A.');
        self::assertContains($shared, $this->pageSectionIds($pageB, $admin), 'The same section row must also render on page B (reuse).');

        // 2. Bulk-remove the shared section from page A: detach only, never destroy.
        $bulk = $this->assertEnvelopeSuccess(
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageA), ['sectionIds' => [$shared]], $admin)
        );
        self::assertSame(1, $bulk['deleted_count'] ?? null, 'Exactly one page link must be detached.');

        self::assertNotContains($shared, $this->pageSectionIds($pageA, $admin), 'Bulk remove must detach the section from page A.');
        self::assertContains($shared, $this->pageSectionIds($pageB, $admin), 'Bulk remove must NOT destroy a shared section: it stays on page B.');

        // 3. Re-attach to A so the section sits on two pages again, warm both
        //    admin trees, then permanently destroy it.
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageA), ['sectionId' => $shared], $admin)
        );
        self::assertContains($shared, $this->pageSectionIds($pageA, $admin), 'Section re-attached to page A.');
        self::assertContains($shared, $this->pageSectionIds($pageB, $admin), 'Section still on page B before destroy.');

        // Destroy returns 204 No Content (empty body), so issue it directly
        // rather than through jsonRequest(), which would try to JSON-decode the
        // empty response.
        $this->client->request(
            'DELETE',
            sprintf('/cms-api/v1/admin/sections/%d', $shared),
            [],
            [],
            $this->authHeaders($admin)
        );
        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $this->client->getResponse()->getStatusCode(),
            'Permanent destroy returns 204 No Content.'
        );

        // Destroy must invalidate the cached section tree of BOTH pages that
        // referenced the section — not just the page the request named.
        self::assertNotContains($shared, $this->pageSectionIds($pageA, $admin), 'Destroyed section must be gone from page A.');
        self::assertNotContains($shared, $this->pageSectionIds($pageB, $admin), 'Destroyed section must be gone from page B (shared-page cache invalidated).');
    }

    private function createPage(string $keyword, string $url, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => $url,
            ], $token),
            Response::HTTP_CREATED
        );
        self::assertIsInt($data['id'] ?? null, 'Created page must expose an integer id.');

        return (int) $data['id'];
    }

    private function createPageSection(int $pageId, int $styleId, int $position, string $name, string $token): int
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/create', $pageId),
                ['styleId' => $styleId, 'position' => $position, 'name' => $name],
                $token
            ),
            Response::HTTP_CREATED
        );
        self::assertIsInt($data['id'] ?? null, 'Section create must return an integer id.');

        return (int) $data['id'];
    }

    /**
     * Every section id in the page's admin tree (recursively over children).
     *
     * @return list<int>
     */
    private function pageSectionIds(int $pageId, string $token): array
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token)
        );

        $ids = [];
        $walk = function (array $sections) use (&$walk, &$ids): void {
            foreach ($sections as $section) {
                $node = $this->asArray($section);
                $ids[] = $this->asInt($node['id'] ?? null);
                if (isset($node['children'])) {
                    $walk($this->asList($node['children']));
                }
            }
        };
        $walk($this->asList($data['sections'] ?? null));

        return $ids;
    }

    private function styleId(string $name): int
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Style::class, $style, sprintf('Seeded style "%s" must exist.', $name));

        return (int) $style->getId();
    }
}
