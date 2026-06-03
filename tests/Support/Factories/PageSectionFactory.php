<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Page;
use App\Entity\PagesSection;
use App\Entity\PageType;
use App\Entity\Section;
use App\Entity\Style;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-prefixed pages, form sections, their `rel_pages_sections` link,
 * and (optionally) group ACL grants — the graph the frontend
 * {@see \App\Controller\Api\V1\Frontend\FormController} and
 * {@see \App\Service\CMS\FormValidationService} validate against.
 *
 * Sections are created exactly like production
 * ({@see \App\Service\CMS\Admin\SectionCreationService}: new Section + setName +
 * setStyle), using the seeded `form-record` / `form-log` styles. Pages reuse a
 * seeded {@see PageType} and the `web` page-access lookup. Everything is created
 * through the real EntityManager inside the DAMA transaction and rolled back at
 * tearDown; the keyword is deterministic (anti-flakiness) and qa-prefixed.
 */
final class PageSectionFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ACLService $aclService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * Create a page with one linked form section.
     *
     * @return array{0: Page, 1: Section}
     */
    public function createFormPage(
        string $keyword,
        bool $openAccess,
        string $styleName = 'form-record',
    ): array {
        $page = $this->createPage($keyword, $openAccess);
        $section = $this->createSection($keyword . '_form', $styleName);
        $this->linkSectionToPage($page, $section, 10);

        return [$page, $section];
    }

    public function createPage(string $keyword, bool $openAccess): Page
    {
        $existing = $this->em->getRepository(Page::class)->findOneBy(['keyword' => $keyword]);
        if ($existing instanceof Page) {
            return $existing;
        }

        $page = new Page();
        $page->setKeyword($keyword);
        $page->setUrl('/' . str_replace('_', '-', $keyword));
        $page->setPageType($this->anyPageType());
        $page->setPageAccessType($this->webAccessType());
        $page->setIsHeadless(false);
        $page->setIsOpenAccess($openAccess);
        $this->em->persist($page);
        $this->em->flush();

        // DAMA reuses auto-increment IDs across tests while Redis persists, so a
        // previous run's cached page-by-keyword resolution, section hierarchy, or
        // ACL for the same id/keyword would otherwise leak in. Bump the relevant
        // category generations so every page-derived lookup recomputes from the
        // DB (which holds this test's fresh rows inside the DAMA transaction).
        $this->invalidatePageScopedCaches();

        return $page;
    }

    public function createSection(string $name, string $styleName = 'form-record'): Section
    {
        $section = new Section();
        $section->setName($name);
        $section->setStyle($this->style($styleName));
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    public function linkSectionToPage(Page $page, Section $section, int $position = 10): PagesSection
    {
        $link = new PagesSection();
        $link->setPage($page);
        $link->setSection($section);
        $link->setPosition($position);
        $this->em->persist($link);
        $this->em->flush();

        // The section-belongs-to-page check reads a cached hierarchy keyed by the
        // (reused) page id; refresh it so the new link is observed.
        $this->invalidatePageScopedCaches();

        return $link;
    }

    /**
     * Grant a group full or partial ACL on a page and drop the cached ACL so the
     * grant is observed on the next check.
     *
     * The test cache is Redis-backed and persists across tests/runs, while DAMA
     * only rolls back the DB and reuses auto-increment page IDs — so a previous
     * test's cached ACL for the same page id would otherwise leak in.
     * {@see ACLService::hasAccess} caches three layers (an entity-scoped outer
     * item, a category-only inner item, and the {@see \App\Repository\AclRepository}
     * list), so the only reliable clear is bumping the permissions category
     * generation, which is part of every key.
     *
     * @param list<int> $affectedUserIds accepted for readability; the category
     *                                   bump already covers every user
     */
    public function grantGroupAcl(
        Page $page,
        Group $group,
        bool $select,
        bool $insert,
        bool $update,
        bool $delete,
        array $affectedUserIds = [],
    ): void {
        $this->aclService->addGroupAcl($page, $group, $select, $insert, $update, $delete, $this->em);
        $this->em->flush();

        $this->invalidatePageScopedCaches();
    }

    /**
     * Bump the page/section/permissions category generations so every cached
     * page-by-keyword lookup, section hierarchy, and ACL layer recomputes from
     * the DB (see {@see createPage}).
     */
    public function invalidatePageScopedCaches(): void
    {
        foreach ([
            CacheService::CATEGORY_PAGES,
            CacheService::CATEGORY_SECTIONS,
            CacheService::CATEGORY_PERMISSIONS,
        ] as $category) {
            $this->cache->withCategory($category)->invalidateCategory();
        }
    }

    private function anyPageType(): PageType
    {
        $pageType = $this->em->getRepository(PageType::class)->findOneBy([]);
        if (!$pageType instanceof PageType) {
            throw new \RuntimeException('No PageType seeded. Run: composer test:reset-db');
        }

        return $pageType;
    }

    private function webAccessType(): Lookup
    {
        $lookup = $this->lookupService->findByTypeAndCode(LookupService::PAGE_ACCESS_TYPES, 'web');
        if (!$lookup instanceof Lookup) {
            throw new \RuntimeException('Missing pageAccessTypes/web lookup. Run: composer test:reset-db');
        }

        return $lookup;
    }

    private function style(string $name): Style
    {
        $style = $this->em->getRepository(Style::class)->findOneBy(['name' => $name]);
        if (!$style instanceof Style) {
            throw new \RuntimeException(sprintf('Missing seeded style "%s". Run: composer test:reset-db', $name));
        }

        return $style;
    }
}
