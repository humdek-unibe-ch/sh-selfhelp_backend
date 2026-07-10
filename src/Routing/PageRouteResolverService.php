<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Routing;

use App\Repository\PageRouteRepository;
use App\Service\Cache\Core\CacheService;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Resolves a public URL path to a CMS page keyword + route params using the
 * DB-driven `page_routes` contract (issue #30).
 *
 * A Symfony {@see RouteCollection} is built from the active `page_routes` rows
 * (cached in {@see CacheService::CATEGORY_PAGES}) and matched with a
 * {@see UrlMatcher}. Static patterns are added before dynamic ones (and then by
 * priority) so an exact path like `/reset` is never shadowed by
 * `/reset/{user_id}/{token}`. Only an exact full-path match wins — partial
 * shapes (`/reset/123`) or extra segments (`/reset/1/tok/extra`) fall through
 * to "not found" (the caller returns 404).
 *
 * Global active-pattern uniqueness + dynamic ambiguity is enforced at write
 * time by {@see RouteConflictValidator} and re-checked out-of-band by the
 * `app:page-routes:check-conflicts` guard command; as a last-line defensive
 * guard the collection builder fails loudly if two active rows ever carry the
 * identical pattern OR the same dynamic path shape (e.g. `/team/{record_id}`
 * and `/team/{slug}`), rather than letting the matcher pick non-deterministically
 * by order.
 *
 * IMPORTANT: a successful resolve only maps a path to a page. The resolved page
 * still applies every normal access rule (page ACL, published/draft, preview,
 * platform/language, data-access security, own_entries_only, record scoping) in
 * {@see \App\Service\CMS\Frontend\PageService}. Open-access on the
 * `/pages/resolve` API route does NOT make the page itself public.
 */
class PageRouteResolverService
{
    private const CACHE_KEY = 'page_routes_resolver_rows';

    public function __construct(
        private readonly PageRouteRepository $pageRouteRepository,
        private readonly CacheService $cache,
        private readonly ?string $env = null,
    ) {
    }

    /**
     * Resolve a public path to a page.
     *
     * @return array{keyword:string, page_id:int, route_params:array<string,string>, route_requirements:array<string,string>, matched_pattern:string, is_canonical:bool, canonical_url:?string}|null
     *         Null when no active route matches the full path.
     */
    public function resolve(string $path): ?array
    {
        $normalized = $this->normalizePath($path);
        $rows = $this->getRows();
        $collection = $this->buildCollection($rows);

        $matcher = new UrlMatcher($collection, new RequestContext('', 'GET'));

        try {
            $matched = $matcher->match($normalized);
        } catch (ResourceNotFoundException | MethodNotAllowedException) {
            return null;
        }

        $keyword = isset($matched['_keyword']) && is_string($matched['_keyword']) ? $matched['_keyword'] : null;
        if ($keyword === null) {
            return null;
        }
        $pageId = isset($matched['_page_id']) && is_numeric($matched['_page_id']) ? (int) $matched['_page_id'] : 0;
        $pattern = isset($matched['_pattern']) && is_string($matched['_pattern']) ? $matched['_pattern'] : $normalized;
        $isCanonical = !empty($matched['_canonical']);

        $routeParams = [];
        foreach ($matched as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, '_')) {
                continue;
            }
            if (is_scalar($value)) {
                $routeParams[$key] = (string) $value;
            }
        }

        $routeRequirements = [];
        foreach ($rows as $row) {
            if ($row['path_pattern'] === $pattern) {
                $routeRequirements = $row['requirements'];
                break;
            }
        }

        return [
            'keyword' => $keyword,
            'page_id' => $pageId,
            'route_params' => $routeParams,
            'route_requirements' => $routeRequirements,
            'matched_pattern' => $pattern,
            'is_canonical' => $isCanonical,
            'canonical_url' => $this->canonicalUrlFromRows($rows, $pageId),
        ];
    }

    /**
     * The canonical path for a page, derived from the cached active rows.
     * Returns the canonical route pattern, or null when the page has none.
     */
    public function getCanonicalUrlForPage(int $pageId): ?string
    {
        return $this->canonicalUrlFromRows($this->getRows(), $pageId);
    }

    /**
     * Invalidate the cached resolver rows. Call after any create/update/delete
     * of pages or page_routes (admin editor, import, wizard).
     */
    public function invalidate(): void
    {
        $this->cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateItem(self::CACHE_KEY);
    }

    /**
     * Cached active-route rows. Cache is skipped in dev for easier authoring
     * (mirrors {@see \App\Routing\ApiRouteLoader}).
     *
     * @return list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}>
     */
    private function getRows(): array
    {
        if ($this->env === 'dev') {
            return $this->pageRouteRepository->findActiveRoutesForResolver();
        }

        /** @var list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}> $rows */
        $rows = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getList(self::CACHE_KEY, fn (): array => $this->pageRouteRepository->findActiveRoutesForResolver());

        return $rows;
    }

    /**
     * Build the Symfony RouteCollection from active rows, ordered
     * static-before-dynamic then priority DESC then id ASC.
     *
     * @param list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}> $rows
     */
    private function buildCollection(array $rows): RouteCollection
    {
        usort($rows, static function (array $a, array $b): int {
            $aDynamic = str_contains($a['path_pattern'], '{') ? 1 : 0;
            $bDynamic = str_contains($b['path_pattern'], '{') ? 1 : 0;
            if ($aDynamic !== $bDynamic) {
                return $aDynamic - $bDynamic;
            }
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority'];
            }
            return $a['id'] - $b['id'];
        });

        $collection = new RouteCollection();
        $seenPatterns = [];
        $seenShapes = [];
        foreach ($rows as $row) {
            $pattern = $row['path_pattern'];
            if (isset($seenPatterns[$pattern])) {
                // Two active rows with the identical pattern is genuinely
                // ambiguous and must never happen (RouteConflictValidator
                // blocks it at write time). Fail loudly rather than resolve
                // non-deterministically.
                throw new \RuntimeException(sprintf(
                    'Ambiguous page route: pattern "%s" is owned by more than one active route.',
                    $pattern
                ));
            }
            $seenPatterns[$pattern] = true;

            // Defensive same-shape ambiguity guard: two active DYNAMIC patterns
            // that collapse to the same shape (every `{placeholder}` -> `{*}`)
            // could both match the same incoming path, and the UrlMatcher would
            // silently resolve by collection order. RouteConflictValidator
            // blocks this at write time, but a manual DB edit / partial import
            // could reintroduce it — fail loudly instead of resolving the wrong
            // page. Static patterns keep their literal shape so a static vs
            // dynamic pair at the same segment (`/team/list` vs `/team/{id}`)
            // never trips this.
            if (str_contains($pattern, '{')) {
                $shape = $this->shape($pattern);
                if (isset($seenShapes[$shape])) {
                    throw new \RuntimeException(sprintf(
                        'Ambiguous page routes: "%s" and "%s" share the dynamic path shape "%s".',
                        $seenShapes[$shape],
                        $pattern,
                        $shape
                    ));
                }
                $seenShapes[$shape] = $pattern;
            }

            $route = new Route(
                $pattern,
                [
                    '_keyword' => $row['keyword'],
                    '_page_id' => $row['page_id'],
                    '_pattern' => $pattern,
                    '_canonical' => $row['is_canonical'],
                ],
                $row['requirements'],
                [],
                '',
                [],
                ['GET']
            );
            $collection->add('page_route_' . $row['id'], $route);
        }

        return $collection;
    }

    /**
     * @param list<array{id:int, page_id:int, keyword:string, path_pattern:string, requirements:array<string,string>, is_canonical:bool, priority:int}> $rows
     */
    private function canonicalUrlFromRows(array $rows, int $pageId): ?string
    {
        foreach ($rows as $row) {
            if ($row['page_id'] === $pageId && $row['is_canonical']) {
                return $row['path_pattern'];
            }
        }

        return null;
    }

    /**
     * Collapse every `{placeholder}` to a wildcard so two dynamic patterns that
     * can match the same path share a shape. Mirrors
     * {@see RouteConflictValidator::shape()} (kept inline so the resolver stays
     * dependency-light for its hot path).
     */
    private function shape(string $pattern): string
    {
        return preg_replace('/\{[^}]+\}/', '{*}', $pattern) ?? $pattern;
    }

    /**
     * Normalize an incoming public path: ensure a single leading slash, drop a
     * trailing slash (except the root), and treat an empty path as the root.
     * The query param arrives already URL-decoded from Symfony.
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }
}
