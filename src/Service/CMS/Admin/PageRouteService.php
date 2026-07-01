<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Page;
use App\Entity\PageRoute;
use App\Repository\PageRepository;
use App\Repository\PageRouteRepository;
use App\Routing\PageRouteResolverService;
use App\Routing\RouteConflictValidator;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-side CRUD/sync for a page's `page_routes` (issue #30).
 *
 * The admin page editor exposes a locked "Routes" panel that edits the full set
 * of patterns for one page without recreating the page. This service is the
 * single place that turns a desired route set into persisted rows: it
 * normalizes patterns, runs the global {@see RouteConflictValidator}, enforces
 * the "exactly one canonical" rule, persists/updates/deletes rows, and
 * invalidates the resolver cache. It is also reused by the page importer and the
 * create-list/detail wizard (Phases 5/6).
 */
class PageRouteService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PageRepository $pageRepository,
        private readonly PageRouteRepository $pageRouteRepository,
        private readonly RouteConflictValidator $conflictValidator,
        private readonly PageRouteResolverService $routeResolver,
    ) {
    }

    /**
     * Derive a canonical, active route descriptor from a page URL so a freshly
     * created page is reachable by URL immediately (used by
     * {@see AdminPageService::createPage()} for the create-page modal).
     *
     * Accepts both the modern Symfony `{param}` syntax and the legacy AltoRouter
     * tokens (`[i:nav]`, `[a:slug]`, `[h:hash]`, `[*:path]`, `[name]`), converting
     * the latter to `{param}` placeholders and collecting matching requirement
     * regexes. Params named `nav`/`id` or ending in `_id` default to a numeric
     * (`\d+`) requirement when none is supplied. Returns `null` for an empty URL.
     *
     * Pure/stateless so it is unit-testable without the service container.
     *
     * @return array{path_pattern: string, requirements: array<string, string>|null, is_canonical: bool, is_active: bool, priority: int}|null
     */
    public static function buildCanonicalRouteFromUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        /** @var array<string, string> $requirements */
        $requirements = [];

        $pattern = preg_replace_callback(
            '/\[(?:([ia*h]):)?([a-zA-Z_][a-zA-Z0-9_]*)\]/',
            static function (array $matches) use (&$requirements): string {
                $type = $matches[1];
                $name = $matches[2];
                $byType = ['i' => '\\d+', 'a' => '[0-9A-Za-z]+', 'h' => '[0-9A-Fa-f]+', '*' => '.+'];
                if (isset($byType[$type])) {
                    $requirements[$name] = $byType[$type];
                }

                return '{' . $name . '}';
            },
            $url
        );
        $pattern = is_string($pattern) ? $pattern : $url;

        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $found) > 0) {
            foreach ($found[1] as $name) {
                if (!isset($requirements[$name]) && ($name === 'nav' || $name === 'id' || str_ends_with($name, '_id'))) {
                    $requirements[$name] = '\\d+';
                }
            }
        }

        return [
            'path_pattern' => $pattern,
            'requirements' => $requirements === [] ? null : $requirements,
            'is_canonical' => true,
            'is_active' => true,
            'priority' => 0,
        ];
    }

    /**
     * Serialize a page's routes for the admin API (round-trips with
     * {@see syncRoutes()}).
     *
     * @return list<array{id:int, path_pattern:string, requirements:array<string,string>|null, is_canonical:bool, is_active:bool, priority:int}>
     */
    public function getRoutesForPage(int $pageId): array
    {
        $routes = $this->pageRouteRepository->findByPageId($pageId);
        $result = [];
        foreach ($routes as $route) {
            $result[] = $this->serializeRoute($route);
        }

        return $result;
    }

    /**
     * Invalidate the DB-driven route resolver cache. Call after a RAW page
     * delete: the page's `page_routes` rows are removed by FK ON DELETE CASCADE,
     * but the resolver caches its active-row snapshot (prod only — dev bypasses
     * the cache), so a deleted page's pattern could keep resolving until the
     * cache expires. Page create/update already invalidate via {@see syncRoutes()}.
     */
    public function invalidateResolverCache(): void
    {
        $this->routeResolver->invalidate();
    }

    /**
     * @return array{id:int, path_pattern:string, requirements:array<string,string>|null, is_canonical:bool, is_active:bool, priority:int}
     */
    public function serializeRoute(PageRoute $route): array
    {
        return [
            'id' => (int) $route->getId(),
            'path_pattern' => (string) $route->getPathPattern(),
            'requirements' => $route->getRequirements(),
            'is_canonical' => $route->isCanonical(),
            'is_active' => $route->isActive(),
            'priority' => $route->getPriority(),
        ];
    }

    /**
     * Replace a page's route set with the desired set (create new, update by id,
     * delete the rest). Validates global conflicts, enforces a single canonical
     * route, persists, and invalidates the resolver cache.
     *
     * Must run inside the caller's transaction (the page editor wraps the whole
     * page update in one). Calls flush() to persist the route create/update/delete
     * set; because Doctrine flushes the whole unit of work, any other pending
     * change in the same transaction (e.g. the page entity the editor mutated
     * before calling this) is flushed too. Order is therefore intentional: the
     * caller mutates the page first, then delegates routes here.
     *
     * @param list<array<string, mixed>> $desired Each item: {id?, path_pattern, requirements?, is_canonical?, is_active?, priority?}
     */
    public function syncRoutes(int $pageId, array $desired): void
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $normalized = $this->normalizeDesired($desired);
        $this->assertNoConflicts($normalized, $pageId);

        $existing = $this->pageRouteRepository->findByPageId($pageId);
        /** @var array<int, PageRoute> $existingById */
        $existingById = [];
        foreach ($existing as $route) {
            $existingById[(int) $route->getId()] = $route;
        }

        /** @var array<int, true> $keptIds */
        $keptIds = [];
        foreach ($normalized as $item) {
            $id = $item['id'];
            if ($id !== null && isset($existingById[$id])) {
                $route = $existingById[$id];
            } else {
                $route = new PageRoute();
                $route->setPage($page);
                $this->entityManager->persist($route);
            }

            $route->setPathPattern($item['path_pattern']);
            $route->setRequirements($item['requirements']);
            $route->setIsCanonical($item['is_canonical']);
            $route->setIsActive($item['is_active']);
            $route->setPriority($item['priority']);
            $route->setUpdatedAt(new \DateTimeImmutable());

            if ($id !== null && isset($existingById[$id])) {
                $keptIds[$id] = true;
            }
        }

        // Delete existing routes that are no longer in the desired set.
        foreach ($existing as $route) {
            if (!isset($keptIds[(int) $route->getId()])) {
                $this->entityManager->remove($route);
            }
        }

        $this->entityManager->flush();
        $this->routeResolver->invalidate();
    }

    /**
     * Normalize + validate the desired set shape, enforcing exactly one
     * canonical route among the active ones.
     *
     * @param list<array<string, mixed>> $desired
     * @return list<array{id:int|null, path_pattern:string, requirements:array<string,string>|null, is_canonical:bool, is_active:bool, priority:int}>
     */
    private function normalizeDesired(array $desired): array
    {
        $normalized = [];
        $activeCanonicalCount = 0;
        $firstActiveIndex = null;

        foreach ($desired as $item) {
            $pattern = $this->normalizePattern($this->asString($item['path_pattern'] ?? ''));
            if ($pattern === '') {
                throw $this->routeException('Each route requires a non-empty path_pattern.');
            }

            $requirements = null;
            if (isset($item['requirements']) && is_array($item['requirements'])) {
                $requirements = [];
                foreach ($item['requirements'] as $key => $value) {
                    if (is_scalar($value)) {
                        $requirements[(string) $key] = (string) $value;
                    }
                }
                if ($requirements === []) {
                    $requirements = null;
                }
            }

            $isActive = !array_key_exists('is_active', $item) || (bool) $item['is_active'];
            $isCanonical = array_key_exists('is_canonical', $item) && (bool) $item['is_canonical'];

            $idValue = $item['id'] ?? null;
            $id = is_numeric($idValue) ? (int) $idValue : null;

            $normalized[] = [
                'id' => $id,
                'path_pattern' => $pattern,
                'requirements' => $requirements,
                'is_canonical' => $isCanonical,
                'is_active' => $isActive,
                'priority' => is_numeric($item['priority'] ?? null) ? (int) $item['priority'] : 0,
            ];

            if ($isActive) {
                if ($firstActiveIndex === null) {
                    $firstActiveIndex = count($normalized) - 1;
                }
                if ($isCanonical) {
                    $activeCanonicalCount++;
                }
            }
        }

        if ($activeCanonicalCount > 1) {
            throw $this->routeException('Only one active route can be marked as canonical.');
        }

        // Auto-promote the first active route to canonical when none was chosen
        // so the resolver always has a canonical URL for the page.
        if ($activeCanonicalCount === 0 && $firstActiveIndex !== null) {
            $canonicalItem = $normalized[$firstActiveIndex];
            $canonicalItem['is_canonical'] = true;
            $normalized[$firstActiveIndex] = $canonicalItem;
        }

        return $normalized;
    }

    /**
     * @param list<array{id:int|null, path_pattern:string, requirements:array<string,string>|null, is_canonical:bool, is_active:bool, priority:int}> $normalized
     */
    private function assertNoConflicts(array $normalized, int $pageId): void
    {
        $activePatterns = [];
        foreach ($normalized as $item) {
            if ($item['is_active']) {
                $activePatterns[] = ['path_pattern' => $item['path_pattern']];
            }
        }

        $conflicts = $this->conflictValidator->findConflictsForSet($activePatterns, $pageId);
        if ($conflicts !== []) {
            throw $this->routeException($conflicts[0]['message']);
        }
    }

    /**
     * Ensure a leading slash and strip a trailing slash (except for the root).
     */
    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '';
        }
        if ($pattern[0] !== '/') {
            $pattern = '/' . $pattern;
        }
        if ($pattern !== '/' && str_ends_with($pattern, '/')) {
            $pattern = rtrim($pattern, '/');
        }

        return $pattern;
    }

    private function routeException(string $message): \App\Exception\ServiceException
    {
        return new \App\Exception\ServiceException($message, Response::HTTP_CONFLICT);
    }
}
