<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Entity\Page;
use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Repository\PageVersionRepository;
use App\Repository\SectionRepository;
use App\Service\CMS\UserPermissionService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\UserContextAwareService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decides which configuration of a page a request acts on, once, for everything
 * that serves or changes a part of that page.
 *
 *  - A visitor (anonymous or signed in) is served the **published** version.
 *  - A page that was never published has only its draft, so the draft is what
 *    is served (the page endpoint does the same).
 *  - The **draft** is used on an explicit preview request only when the caller
 *    may edit the page: the `admin.page.update` permission plus the update
 *    data-access grant on that page (the admin role passes both). A guest
 *    asking for a preview is refused (401); a signed-in caller without those
 *    rights gets 403 and nothing from the draft.
 *
 * The page response ({@see \App\Service\CMS\Frontend\PageService}) uses
 * {@see self::assertPreviewAllowed()} and {@see self::servesPublished()} for
 * the same decision, so the page and every endpoint behind it cannot disagree.
 */
class EffectivePageResolver extends BaseService implements ResetInterface
{
    private const PREVIEW_PERMISSION = 'admin.page.update';

    /** @var array<string, EffectivePage> */
    private array $memo = [];

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageVersionRepository $pageVersionRepository,
        private readonly SectionRepository $sectionRepository,
        private readonly UserContextAwareService $userContext,
        private readonly UserPermissionService $userPermissions,
        private readonly DataAccessSecurityService $dataAccess,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Whether a request is served the published version of the page.
     *
     * Call {@see self::assertPreviewAllowed()} first when `$preview` may be
     * true: this method only picks the snapshot, it does not authorize.
     */
    public function servesPublished(Page $page, bool $preview): bool
    {
        if ($preview || $page->getPublishedVersionId() === null) {
            return false;
        }

        return $this->pageVersionRepository->find($page->getPublishedVersionId()) !== null;
    }

    /**
     * A preview request needs edit rights on the page. Every route that can
     * serve or apply draft configuration calls this before reading the draft.
     *
     * @throws ServiceException 401 without a signed-in user; 403 without edit rights
     */
    public function assertPreviewAllowed(Page $page, bool $preview): void
    {
        if (!$preview) {
            return;
        }

        $user = $this->userContext->getCurrentUser();
        if ($user === null) {
            throw new ServiceException('Authentication required to preview unpublished drafts', Response::HTTP_UNAUTHORIZED);
        }

        $userId = (int) $user->getId();
        if ($this->dataAccess->userHasAdminRole($userId)) {
            return;
        }

        $permissions = $this->userPermissions->getUserPermissions($user);
        if (!in_array(self::PREVIEW_PERMISSION, $permissions, true)) {
            throw new ServiceException('Edit rights are required to preview unpublished drafts', Response::HTTP_FORBIDDEN);
        }

        $pageId = (int) $page->getId();
        if (!$this->dataAccess->hasPermission(
            $userId,
            LookupService::RESOURCE_TYPES_PAGES,
            $pageId,
            DataAccessSecurityService::PERMISSION_UPDATE,
        )) {
            throw new ServiceException('Edit rights are required to preview unpublished drafts', Response::HTTP_FORBIDDEN);
        }
    }

    /**
     * @throws ServiceException 404 unknown page; 401/403 from {@see self::assertPreviewAllowed()}
     */
    public function forPageId(int $pageId, bool $preview): EffectivePage
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        return $this->forPage($page, $preview);
    }

    /**
     * @throws ServiceException 401/403 from {@see self::assertPreviewAllowed()}
     */
    public function forPage(Page $page, bool $preview): EffectivePage
    {
        $this->assertPreviewAllowed($page, $preview);

        $pageId = (int) $page->getId();
        $publishedId = $this->servesPublished($page, $preview) ? (int) $page->getPublishedVersionId() : null;
        $memoKey = $pageId . ':' . ($publishedId ?? 'draft');
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        if ($publishedId !== null) {
            $version = $this->pageVersionRepository->find($publishedId);
            $json = $version?->getPageJson() ?? [];
            $pageNode = $json['page'] ?? null;
            $sections = is_array($pageNode) && is_array($pageNode['sections'] ?? null) ? $pageNode['sections'] : [];
            /** @var list<array<string, mixed>> $sections */
            $sections = array_values($sections);

            return $this->memo[$memoKey] = EffectivePage::published($pageId, $publishedId, $sections);
        }

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $this->sectionRepository->fetchSectionsHierarchicalByPageId($pageId);

        return $this->memo[$memoKey] = EffectivePage::draft(
            $pageId,
            $rows,
            fn (int $sectionId): array => $this->liveFields($sectionId),
        );
    }

    /**
     * Forget what this request has already resolved: a long-lived worker, or a
     * test that publishes between two requests of one process.
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<string, array<int, string>> field name => language id => stored content
     */
    private function liveFields(int $sectionId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT f.name AS name, t.id_languages AS language_id, t.content AS content'
            . ' FROM sections_fields_translation t JOIN fields f ON f.id = t.id_fields'
            . ' WHERE t.id_sections = :section',
            ['section' => $sectionId],
            ['section' => ParameterType::INTEGER],
        );

        $raw = [];
        foreach ($rows as $row) {
            $raw[$this->asString($row['name'])][$this->asInt($row['language_id'])] = $this->asString($row['content'] ?? '');
        }

        return $raw;
    }
}
