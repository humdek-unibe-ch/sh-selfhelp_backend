<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Routing;

use App\Repository\PageRouteRepository;

/**
 * Detects ambiguous / duplicate public route patterns across active
 * `page_routes` (issue #30).
 *
 * The per-page DB unique key only prevents the same pattern twice on one page.
 * This service adds the missing GLOBAL guarantees the resolver needs to stay
 * deterministic:
 *
 *   - "duplicate": another active route already owns the exact pattern.
 *   - "ambiguous": another active route has the same path SHAPE — the pattern
 *     with every `{placeholder}` collapsed to a wildcard — so both could match
 *     the same incoming path. Example rejected pair:
 *         /team/{record_id}
 *         /team/{slug}
 *     Static-vs-dynamic at the same segment (`/team/list` vs `/team/{id}`) has
 *     different shapes and stays allowed: the resolver tries static patterns
 *     first, so it remains unambiguous.
 *
 * Callers: {@see \App\Service\CMS\Admin\PageRouteService} (create/update),
 * the page importer, and the `app:page-routes:check-conflicts` guard command.
 */
class RouteConflictValidator
{
    public const TYPE_DUPLICATE = 'duplicate';
    public const TYPE_AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly PageRouteRepository $pageRouteRepository,
    ) {
    }

    /**
     * Validate a proposed set of route patterns for one page against (a) each
     * other and (b) every OTHER page's active patterns.
     *
     * @param list<array{path_pattern:string}> $routes Proposed patterns for the page.
     * @param int|null $excludePageId The page being created/updated (excluded from the external check).
     * @return list<array{type:string, path_pattern:string, conflicting_pattern:string, conflicting_keyword:?string, message:string}>
     */
    public function findConflictsForSet(array $routes, ?int $excludePageId = null): array
    {
        $conflicts = [];
        $existing = $this->pageRouteRepository->findAllActivePatterns($excludePageId);

        /** @var array<string, true> $seenExact */
        $seenExact = [];
        /** @var array<string, string> $seenShape */
        $seenShape = [];

        foreach ($routes as $route) {
            $pattern = $route['path_pattern'];
            $shape = $this->shape($pattern);

            // (a-1) Internal exact duplicate within the submitted set.
            if (isset($seenExact[$pattern])) {
                $conflicts[] = $this->conflict(
                    self::TYPE_DUPLICATE,
                    $pattern,
                    $pattern,
                    null,
                    sprintf('Route pattern "%s" is listed more than once for this page.', $pattern)
                );
            } else {
                $seenExact[$pattern] = true;
            }

            // (a-2) Internal shape collision within the submitted set.
            if (isset($seenShape[$shape]) && $seenShape[$shape] !== $pattern) {
                $conflicts[] = $this->conflict(
                    self::TYPE_AMBIGUOUS,
                    $pattern,
                    $seenShape[$shape],
                    null,
                    sprintf('Route patterns "%s" and "%s" are ambiguous (same path shape).', $pattern, $seenShape[$shape])
                );
            } elseif (!isset($seenShape[$shape])) {
                $seenShape[$shape] = $pattern;
            }

            // (b) External checks against other pages' active routes.
            foreach ($existing as $row) {
                if ($row['path_pattern'] === $pattern) {
                    $conflicts[] = $this->conflict(
                        self::TYPE_DUPLICATE,
                        $pattern,
                        $row['path_pattern'],
                        $row['keyword'],
                        sprintf('Route pattern "%s" is already used by page "%s".', $pattern, $row['keyword'])
                    );
                } elseif ($this->shape($row['path_pattern']) === $shape) {
                    $conflicts[] = $this->conflict(
                        self::TYPE_AMBIGUOUS,
                        $pattern,
                        $row['path_pattern'],
                        $row['keyword'],
                        sprintf(
                            'Route pattern "%s" is ambiguous with "%s" on page "%s" (same path shape).',
                            $pattern,
                            $row['path_pattern'],
                            $row['keyword']
                        )
                    );
                }
            }
        }

        return $conflicts;
    }

    /**
     * Scan EVERY active route against every other for the conflict guard
     * command. Each conflicting unordered pair is reported once.
     *
     * @return list<array{type:string, path_pattern:string, conflicting_pattern:string, conflicting_keyword:?string, message:string}>
     */
    public function findAllConflicts(): array
    {
        $all = $this->pageRouteRepository->findAllActivePatterns();
        $conflicts = [];

        $count = count($all);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $all[$i];
                $b = $all[$j];
                if ($a['path_pattern'] === $b['path_pattern']) {
                    $conflicts[] = $this->conflict(
                        self::TYPE_DUPLICATE,
                        $a['path_pattern'],
                        $b['path_pattern'],
                        $b['keyword'],
                        sprintf(
                            'Duplicate active route "%s" owned by pages "%s" and "%s".',
                            $a['path_pattern'],
                            $a['keyword'],
                            $b['keyword']
                        )
                    );
                } elseif ($this->shape($a['path_pattern']) === $this->shape($b['path_pattern'])) {
                    $conflicts[] = $this->conflict(
                        self::TYPE_AMBIGUOUS,
                        $a['path_pattern'],
                        $b['path_pattern'],
                        $b['keyword'],
                        sprintf(
                            'Ambiguous routes "%s" (page "%s") and "%s" (page "%s") share a path shape.',
                            $a['path_pattern'],
                            $a['keyword'],
                            $b['path_pattern'],
                            $b['keyword']
                        )
                    );
                }
            }
        }

        return $conflicts;
    }

    /**
     * Collapse every `{placeholder}` to a wildcard so two patterns that can
     * match the same path share a shape.
     */
    public function shape(string $pattern): string
    {
        $shaped = preg_replace('/\{[^}]+\}/', '{*}', $pattern);

        return $shaped ?? $pattern;
    }

    /**
     * @return array{type:string, path_pattern:string, conflicting_pattern:string, conflicting_keyword:?string, message:string}
     */
    private function conflict(string $type, string $pattern, string $conflictingPattern, ?string $conflictingKeyword, string $message): array
    {
        return [
            'type' => $type,
            'path_pattern' => $pattern,
            'conflicting_pattern' => $conflictingPattern,
            'conflicting_keyword' => $conflictingKeyword,
            'message' => $message,
        ];
    }
}
