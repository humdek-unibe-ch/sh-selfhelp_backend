<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Repository\PagesFieldsTranslationRepository;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;

/**
 * ACL-aware public search across page metadata and published display-text fields.
 */
class NavigationSearchService extends BaseService
{
    public function __construct(
        private readonly PageService $pageService,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly NavigationMenuService $navigationMenuService,
        private readonly NavigationSearchIndexService $navigationSearchIndexService,
        private readonly PagesFieldsTranslationRepository $pagesFieldsTranslationRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, string $mode, ?int $languageId = null, ?int $limit = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $languageId = $languageId ?? $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        $navigation = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);
        $searchConfig = $this->searchConfigFromNavigation($navigation);
        $minChars = $searchConfig['min_chars'];
        if (mb_strlen($query) < $minChars) {
            return [];
        }

        $resultLimit = $limit ?? $searchConfig['result_limit'];
        $modeCode = $searchConfig['mode'];

        return match ($modeCode) {
            LookupService::NAVIGATION_SEARCH_MODE_OFF => [],
            LookupService::NAVIGATION_SEARCH_MODE_MENU_PAGES => $this->searchMenuPages($navigation, $query, $resultLimit),
            LookupService::NAVIGATION_SEARCH_MODE_SEARCHABLE_PAGES => $this->searchPageMetadata($mode, $languageId, $query, $resultLimit),
            default => $this->searchContentIndex($mode, $languageId, $query, $resultLimit),
        };
    }

    /**
     * @param array<string, mixed> $navigation
     *
     * @return list<array<string, mixed>>
     */
    private function searchMenuPages(array $navigation, string $query, int $limit): array
    {
        $needle = mb_strtolower($query);
        $results = [];
        $menus = $navigation['menus'] ?? [];
        if (!is_array($menus)) {
            return [];
        }

        $seenPageIds = [];
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }
            $items = $menu['items'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            /** @var list<array<string, mixed>> $itemList */
            $itemList = array_values($items);
            $this->collectMenuPageHits($itemList, $needle, $results, $seenPageIds);
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $results
     * @param array<int, true> $seenPageIds pages already emitted (a page may sit in several menus)
     */
    private function collectMenuPageHits(array $items, string $needle, array &$results, array &$seenPageIds): void
    {
        foreach ($items as $item) {
            $page = $item['page'] ?? null;
            if (is_array($page)) {
                /** @var array<string, mixed> $pageData */
                $pageData = $page;
                $pageId = $pageData['id'] ?? $pageData['id_pages'] ?? null;
                $pageKey = is_numeric($pageId) ? (int) $pageId : null;
                if ($pageKey === null || !isset($seenPageIds[$pageKey])) {
                    $label = isset($item['label']) && is_string($item['label']) ? $item['label'] : null;
                    $haystacks = [
                        $this->stringField($pageData, 'title'),
                        $this->stringField($pageData, 'keyword'),
                        $this->stringField($pageData, 'url'),
                        $label ?? '',
                    ];
                    foreach ($haystacks as $haystack) {
                        if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                            $results[] = $this->formatHit($pageData, $label, 'menu');
                            if ($pageKey !== null) {
                                $seenPageIds[$pageKey] = true;
                            }
                            break;
                        }
                    }
                }
            }
            $children = $item['children'] ?? [];
            if (is_array($children) && $children !== []) {
                /** @var list<array<string, mixed>> $childList */
                $childList = array_values($children);
                $this->collectMenuPageHits($childList, $needle, $results, $seenPageIds);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchPageMetadata(string $mode, int $languageId, string $query, int $limit): array
    {
        $navigation = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);
        $searchConfig = $this->searchConfigFromNavigation($navigation);
        $tree = $this->pageService->getAllAccessiblePagesForUser($mode, false, $languageId);
        $visibilityOverrides = $this->loadSearchVisibilityOverrides($tree);
        // Cross-language metadata: a query typed in ANY site language must find
        // the page even when the current UI language uses a different title
        // ("Impressum" from the English UI). Hits still render current-language.
        $allLanguageTexts = $this->pagesFieldsTranslationRepository->fetchDisplayFieldTextsAllLanguages(
            array_keys($this->flattenPageIds($tree)),
        );
        $needle = mb_strtolower($query);
        $results = [];
        $seenPageIds = [];
        $walk = function (array $nodes) use (&$walk, &$results, &$seenPageIds, $needle, $visibilityOverrides, $searchConfig, $allLanguageTexts): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                /** @var array<string, mixed> $pageNode */
                $pageNode = $node;
                $pageId = $pageNode['id_pages'] ?? $pageNode['id'] ?? null;
                if (!is_numeric($pageId) || !$this->isPageSearchable((int) $pageId, $visibilityOverrides, $searchConfig['default_visibility'])) {
                    $children = $node['children'] ?? [];
                    if (is_array($children) && $children !== []) {
                        $walk($children);
                    }
                    continue;
                }
                $pageKey = (int) $pageId;
                if (isset($seenPageIds[$pageKey])) {
                    $children = $node['children'] ?? [];
                    if (is_array($children) && $children !== []) {
                        $walk($children);
                    }
                    continue;
                }
                $title = $this->stringField($pageNode, 'title');
                $keyword = $this->stringField($pageNode, 'keyword');
                $url = $this->stringField($pageNode, 'url');
                $description = $this->stringField($pageNode, 'description');
                $blob = mb_strtolower(implode(' ', [
                    $title,
                    $keyword,
                    $url,
                    $description,
                    implode(' ', $allLanguageTexts[$pageKey] ?? []),
                ]));
                if (str_contains($blob, $needle)) {
                    $results[] = $this->formatHit($pageNode, $title !== '' ? $title : null, 'page_metadata');
                    $seenPageIds[$pageKey] = true;
                }
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };
        $walk($tree);

        return array_slice($results, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchContentIndex(string $mode, int $languageId, string $query, int $limit): array
    {
        $navigation = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);
        $searchConfig = $this->searchConfigFromNavigation($navigation);
        $metadataHits = $this->searchPageMetadata($mode, $languageId, $query, $limit);
        $foundPageIds = [];
        foreach ($metadataHits as $hit) {
            $pageId = $hit['page_id'] ?? null;
            if (is_int($pageId) || (is_string($pageId) && is_numeric($pageId))) {
                $foundPageIds[(int) $pageId] = true;
            }
        }

        $accessible = $this->pageService->getAllAccessiblePagesForUser($mode, false, $languageId);
        $accessibleIds = $this->flattenPageIds($accessible);
        $visibilityOverrides = $this->loadSearchVisibilityOverrides($accessible);

        $indexHits = $this->navigationSearchIndexService->searchIndexedContent(
            $languageId,
            $query,
            $limit,
            $accessibleIds,
        );

        $results = $metadataHits;
        foreach ($indexHits as $hit) {
            $pageId = (int) $hit['page_id'];
            if (isset($foundPageIds[$pageId])) {
                continue;
            }
            if (!$this->isPageSearchable($pageId, $visibilityOverrides, $searchConfig['default_visibility'])) {
                continue;
            }
            $results[] = [
                'page_id' => $pageId,
                'keyword' => $hit['keyword'],
                'url' => $hit['url'],
                'title' => $hit['snippet_source'] === 'title'
                    ? $hit['snippet_text']
                    : $hit['keyword'],
                'snippet' => $hit['snippet_source'] === 'content' ? $hit['snippet_text'] : null,
                'source' => $hit['snippet_source'] === 'content' ? 'content' : 'page_metadata',
            ];
            $foundPageIds[$pageId] = true;
            if (count($results) >= $limit) {
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function formatHit(array $page, ?string $label, string $source): array
    {
        return [
            'page_id' => $page['id'] ?? $page['id_pages'] ?? null,
            'keyword' => $page['keyword'] ?? '',
            'url' => $page['url'] ?? null,
            'title' => $label ?? $page['title'] ?? $page['keyword'] ?? '',
            'snippet' => null,
            'source' => $source,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tree
     *
     * @return array<int, true>
     */
    private function flattenPageIds(array $tree): array
    {
        $ids = [];
        $walk = function (array $nodes) use (&$ids, &$walk): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = $node['id_pages'] ?? $node['id'] ?? null;
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };
        $walk($tree);

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchPageMetadataOnly(string $query, string $mode, ?int $languageId = null, ?int $limit = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $languageId = $languageId ?? $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        $navigation = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);
        $searchConfig = $this->searchConfigFromNavigation($navigation);
        $minChars = $searchConfig['min_chars'];
        if (mb_strlen($query) < $minChars) {
            return [];
        }
        $resultLimit = $limit ?? $searchConfig['result_limit'];

        return $this->searchPageMetadata($mode, $languageId, $query, $resultLimit);
    }

    /**
     * @param array<string, mixed> $navigation
     *
     * @return array{min_chars: int, result_limit: int, mode: string, default_visibility: string}
     */
    private function searchConfigFromNavigation(array $navigation): array
    {
        $searchConfig = $navigation['search'] ?? null;
        if (!is_array($searchConfig)) {
            return [
                'min_chars' => 2,
                'result_limit' => 8,
                'mode' => LookupService::NAVIGATION_SEARCH_MODE_CONTENT_INDEX,
                'default_visibility' => 'all_accessible_pages',
            ];
        }

        $minChars = $searchConfig['min_chars'] ?? 2;
        $resultLimit = $searchConfig['result_limit'] ?? 8;
        $mode = $searchConfig['mode'] ?? LookupService::NAVIGATION_SEARCH_MODE_CONTENT_INDEX;
        $defaultVisibility = $searchConfig['default_visibility'] ?? 'all_accessible_pages';

        return [
            'min_chars' => is_int($minChars) ? $minChars : (is_numeric($minChars) ? (int) $minChars : 2),
            'result_limit' => is_int($resultLimit) ? $resultLimit : (is_numeric($resultLimit) ? (int) $resultLimit : 8),
            'mode' => is_string($mode) ? $mode : LookupService::NAVIGATION_SEARCH_MODE_CONTENT_INDEX,
            'default_visibility' => is_string($defaultVisibility) ? $defaultVisibility : 'all_accessible_pages',
        ];
    }

    /**
     * @param list<array<string, mixed>> $tree
     *
     * @return array<int, string>
     */
    private function loadSearchVisibilityOverrides(array $tree): array
    {
        $pageIds = array_keys($this->flattenPageIds($tree));
        if ($pageIds === []) {
            return [];
        }

        $properties = $this->pagesFieldsTranslationRepository->fetchPropertyFieldsForPages($pageIds, ['search_visibility']);
        $out = [];
        foreach ($properties as $pageId => $fields) {
            $value = $fields['search_visibility'] ?? null;
            if (is_string($value) && $value !== '') {
                $out[(int) $pageId] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $visibilityOverrides
     */
    private function isPageSearchable(int $pageId, array $visibilityOverrides, string $defaultVisibility): bool
    {
        $override = $visibilityOverrides[$pageId] ?? 'inherit';
        if ($override === 'hidden') {
            return false;
        }
        if ($override === 'visible') {
            return true;
        }

        return $defaultVisibility === 'all_accessible_pages';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }
}
