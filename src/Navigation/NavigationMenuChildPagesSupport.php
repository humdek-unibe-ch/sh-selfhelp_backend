<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Navigation;

use App\Entity\Page;
use App\Repository\PageRepository;

/**
 * Resolves CMS page-tree children for bulk stored menu-item creation.
 */
final class NavigationMenuChildPagesSupport
{
    public function __construct(
        private readonly PageRepository $pageRepository,
    ) {
    }

    /**
     * @param list<int> $selectedDirectChildPageIds
     *
     * @return list<array{page: Page, parent_page_id: int}>
     */
    public function resolvePagesToCreate(
        Page $parentPage,
        array $selectedDirectChildPageIds,
        bool $includeDescendants,
    ): array {
        $parentPageId = $parentPage->getId();
        if ($parentPageId === null || $selectedDirectChildPageIds === []) {
            return [];
        }

        $directChildren = $this->loadDirectChildPages($parentPage);
        $directChildById = [];
        foreach ($directChildren as $child) {
            $childId = $child->getId();
            if ($childId !== null) {
                $directChildById[$childId] = $child;
            }
        }

        $selectedSet = array_fill_keys($selectedDirectChildPageIds, true);
        $out = [];

        foreach ($directChildren as $child) {
            $childId = $child->getId();
            if ($childId === null || !isset($selectedSet[$childId])) {
                continue;
            }

            $out[] = ['page' => $child, 'parent_page_id' => $parentPageId];

            if ($includeDescendants) {
                $this->appendDescendantsInTreeOrder($child, $out);
            }
        }

        return $out;
    }

    /**
     * @param list<array{page: Page, parent_page_id: int}> $out
     */
    private function appendDescendantsInTreeOrder(Page $parentPage, array &$out): void
    {
        $parentPageId = $parentPage->getId();
        if ($parentPageId === null) {
            return;
        }

        foreach ($this->loadDirectChildPages($parentPage) as $child) {
            $childId = $child->getId();
            if ($childId === null) {
                continue;
            }
            $out[] = ['page' => $child, 'parent_page_id' => $parentPageId];
            $this->appendDescendantsInTreeOrder($child, $out);
        }
    }

    /**
     * @return list<Page>
     */
    public function loadDirectChildPages(Page $parentPage): array
    {
        /** @var list<Page> $children */
        $children = $this->pageRepository->createQueryBuilder('p')
            ->andWhere('p.parentPage = :parent')
            ->setParameter('parent', $parentPage)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $children;
    }
}
