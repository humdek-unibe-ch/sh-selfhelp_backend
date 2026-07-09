<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\CmsApp;
use App\Entity\Page;
use App\Entity\PagesSection;
use App\Entity\Section;
use App\Service\CMS\Common\StyleNames;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sole writer of {@see CmsApp} hub FKs.
 *
 * Every assign / unassign / role change / scaffold / page delete / app delete /
 * import path that affects page↔app membership MUST call {@see sync()} after
 * flushing page assignment changes. Controllers and other services must not set
 * hub FKs directly.
 */
final class CmsAppHubSyncService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Rebuild hub FKs on `$app` from its currently assigned pages.
     */
    public function sync(CmsApp $app): void
    {
        /** @var list<Page> $pages */
        $pages = $this->entityManager->getRepository(Page::class)->findBy(['cmsApp' => $app]);

        $byRole = [];
        foreach ($pages as $page) {
            $role = $page->getCmsAppRole();
            if ($role === null || $role === '' || $role === CmsAppRole::OTHER) {
                continue;
            }
            // Primary uniqueness is enforced at assign time; if a duplicate slips
            // through, the first match wins and remaining stay on the page list.
            if (!isset($byRole[$role])) {
                $byRole[$role] = $page;
            }
        }

        $formPage = $byRole[CmsAppRole::FORM] ?? null;
        $app->setFormSection($formPage instanceof Page ? $this->resolveFormSection($formPage) : null);
        $app->setCmsListPage($byRole[CmsAppRole::CMS_LIST] ?? null);
        $app->setCmsDetailPage($byRole[CmsAppRole::CMS_DETAIL] ?? null);
        $app->setPublicListPage($byRole[CmsAppRole::PUBLIC_LIST] ?? null);
        $app->setPublicDetailPage($byRole[CmsAppRole::PUBLIC_DETAIL] ?? null);
        $app->touchUpdatedAt();
        $this->entityManager->flush();
    }

    /**
     * Clear every hub FK (used when deleting the app shell after unassigning pages).
     */
    public function clearHubs(CmsApp $app): void
    {
        $app->setFormSection(null);
        $app->setCmsListPage(null);
        $app->setCmsDetailPage(null);
        $app->setPublicListPage(null);
        $app->setPublicDetailPage(null);
        $app->touchUpdatedAt();
        $this->entityManager->flush();
    }

    private function resolveFormSection(Page $page): ?Section
    {
        /** @var list<PagesSection> $rels */
        $rels = $this->entityManager->getRepository(PagesSection::class)->findBy(['page' => $page]);
        foreach ($rels as $rel) {
            $section = $rel->getSection();
            if (!$section instanceof Section) {
                continue;
            }
            $styleName = $section->getStyle()?->getName();
            if ($styleName === StyleNames::STYLE_FORM_RECORD) {
                return $section;
            }
        }

        // Fallback: first section on the form page (scaffold always puts form-record first).
        foreach ($rels as $rel) {
            $section = $rel->getSection();
            if ($section instanceof Section) {
                return $section;
            }
        }

        return null;
    }
}
