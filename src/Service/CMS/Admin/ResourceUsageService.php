<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Exception\ServiceException;
use App\Plugin\Resource\PluginResourceServiceInterface;
use App\Plugin\Resource\RegisteredResourceTypes;
use App\Repository\ActionRepository;
use App\Repository\PageRepository;
use App\Repository\PagesFieldsTranslationRepository;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\LookupService;
use App\Service\Security\CallerHoldingsService;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ResourceReadIndex;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a plugin resource is used (contract C7): the pages and actions that
 * hold it, limited to what the caller may see in the admin.
 *
 * Unknown type → 404. Without the view grant on the item → 403 (the
 * superuser passes). Pages need `admin.page.read` plus the view grant on the
 * page; actions need `admin.action.read`. `hidden_count` is the number of
 * other usages the caller may not see.
 */
final class ResourceUsageService
{
    private const PERM_PAGE_READ = 'admin.page.read';
    private const PERM_ACTION_READ = 'admin.action.read';

    public function __construct(
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly PluginResourceServiceInterface $resources,
        private readonly ResourceReadIndex $index,
        private readonly DataAccessSecurityService $dataAccess,
        private readonly CallerHoldingsService $holdings,
        private readonly PageRepository $pages,
        private readonly ActionRepository $actions,
        private readonly PagesFieldsTranslationRepository $pageTitles,
        private readonly CmsPreferenceService $cmsPreferences,
    ) {
    }

    /**
     * @return array{
     *     pages: list<array{id: int, keyword: string, title: string|null, draft_uses: bool, published_uses: bool}>,
     *     actions: list<array{id: int, name: string}>,
     *     hidden_count: int
     * }
     */
    public function usage(string $type, int $id, int $callerId): array
    {
        if ($id <= 0 || !$this->registeredTypes->isRegistered($type)) {
            throw new ServiceException('Resource type not found', Response::HTTP_NOT_FOUND);
        }

        $rights = $this->resources->rightsFor($type, [$id], $callerId);
        if (!($rights[$id]['can_view'] ?? false)) {
            throw new ServiceException('Access denied', Response::HTTP_FORBIDDEN);
        }

        $draftIds = $this->index->draftPagesOf($type, $id);
        $publishedIds = $this->index->publishedPagesOf($type, $id);
        $actionIds = $this->index->actionsOf($type, $id);

        $pageIds = array_values(array_unique([...$draftIds, ...$publishedIds]));
        sort($pageIds);
        $draftSet = array_fill_keys($draftIds, true);
        $publishedSet = array_fill_keys($publishedIds, true);

        $mayReadPages = $this->holdings->holdsPermission($callerId, self::PERM_PAGE_READ)
            || $this->holdings->isSuperuser($callerId);
        $mayReadActions = $this->holdings->holdsPermission($callerId, self::PERM_ACTION_READ);

        $visiblePages = [];
        $hiddenPages = 0;
        if ($pageIds !== []) {
            $pagesById = [];
            foreach ($this->pages->findBy(['id' => $pageIds]) as $page) {
                $pagesById[(int) $page->getId()] = $page;
            }
            $titlesByLanguage = $this->pageTitles->fetchTitleByLanguageForPages($pageIds);
            $defaultLanguageId = $this->cmsPreferences->getEffectiveDefaultLanguageId();
            foreach ($pageIds as $pageId) {
                $page = $pagesById[$pageId] ?? null;
                $canSee = $mayReadPages && $page !== null && $this->dataAccess->hasPermission(
                    $callerId,
                    LookupService::RESOURCE_TYPES_PAGES,
                    $pageId,
                    DataAccessSecurityService::PERMISSION_READ
                );
                if (!$canSee) {
                    ++$hiddenPages;
                    continue;
                }
                $byLanguage = $titlesByLanguage[$pageId] ?? [];
                $title = $byLanguage[$defaultLanguageId] ?? (array_values($byLanguage)[0] ?? null);
                $visiblePages[] = [
                    'id' => $pageId,
                    'keyword' => (string) $page->getKeyword(),
                    'title' => is_string($title) && $title !== '' ? $title : null,
                    'draft_uses' => isset($draftSet[$pageId]),
                    'published_uses' => isset($publishedSet[$pageId]),
                ];
            }
            usort(
                $visiblePages,
                static fn (array $a, array $b): int => strcasecmp($a['keyword'], $b['keyword'])
            );
        }

        $visibleActions = [];
        $hiddenActions = 0;
        if ($actionIds !== []) {
            if (!$mayReadActions) {
                $hiddenActions = count($actionIds);
            } else {
                $actionsById = [];
                foreach ($this->actions->findBy(['id' => $actionIds]) as $action) {
                    $actionsById[(int) $action->getId()] = $action;
                }
                $sorted = $actionIds;
                sort($sorted);
                foreach ($sorted as $actionId) {
                    $action = $actionsById[$actionId] ?? null;
                    if ($action === null) {
                        ++$hiddenActions;
                        continue;
                    }
                    $visibleActions[] = [
                        'id' => $actionId,
                        'name' => (string) ($action->getName() ?? ''),
                    ];
                }
            }
        }

        return [
            'pages' => $visiblePages,
            'actions' => $visibleActions,
            'hidden_count' => $hiddenPages + $hiddenActions,
        ];
    }
}
