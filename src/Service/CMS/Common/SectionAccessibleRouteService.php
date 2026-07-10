<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Common;

use App\Entity\PageRoute;
use App\Repository\PageRouteRepository;
use App\Repository\SectionRepository;
use App\Service\Auth\UserContextService;
use App\Service\Security\DataAccessSecurityService;

/**
 * Route placeholders and requirements visible for a section, filtered by the
 * caller's page read data-access grants. Shared by the interpolation variable
 * picker and the admin query-preview path so both expose the same tokens.
 */
class SectionAccessibleRouteService
{
    public function __construct(
        private readonly SectionRepository $sectionRepository,
        private readonly PageRouteRepository $pageRouteRepository,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
    ) {
    }

    /**
     * @return list<string> Placeholder names without the `route.` prefix.
     */
    public function getRoutePlaceholdersForSection(int $sectionId, ?int $userId): array
    {
        if ($sectionId <= 0) {
            return [];
        }

        $effectiveUserId = $userId ?? UserContextService::GUEST_USER_ID;
        $names = [];

        foreach ($this->accessiblePagesForSection($sectionId, $effectiveUserId) as $pageId) {
            foreach ($this->pageRouteRepository->findByPageId($pageId) as $route) {
                if (!$route instanceof PageRoute || !$route->isActive()) {
                    continue;
                }
                foreach ($this->extractRoutePlaceholders((string) $route->getPathPattern()) as $placeholder) {
                    $names[$placeholder] = true;
                }
            }
        }

        return array_keys($names);
    }

    public function hasAccessiblePagesForSection(int $sectionId, ?int $userId): bool
    {
        if ($sectionId <= 0) {
            return false;
        }

        $effectiveUserId = $userId ?? UserContextService::GUEST_USER_ID;

        return $this->accessiblePagesForSection($sectionId, $effectiveUserId) !== [];
    }

    /**
     * @return array<string, string> Route requirement regex keyed by param name.
     */
    public function getRouteRequirementsForSection(int $sectionId, ?int $userId): array
    {
        if ($sectionId <= 0) {
            return [];
        }

        $effectiveUserId = $userId ?? UserContextService::GUEST_USER_ID;
        $requirements = [];

        foreach ($this->accessiblePagesForSection($sectionId, $effectiveUserId) as $pageId) {
            foreach ($this->pageRouteRepository->findByPageId($pageId) as $route) {
                if (!$route instanceof PageRoute || !$route->isActive()) {
                    continue;
                }
                foreach ($this->decodeRouteRequirements($route) as $name => $pattern) {
                    $requirements[$name] = $pattern;
                }
            }
        }

        return $requirements;
    }

    /**
     * @return list<int>
     */
    private function accessiblePagesForSection(int $sectionId, int $userId): array
    {
        $pageIds = [];
        foreach ($this->sectionRepository->getPagesContainingSections([$sectionId]) as $page) {
            $pageId = (int) ($page['id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }
            if (!$this->dataAccessSecurityService->hasPermission(
                $userId,
                'pages',
                $pageId,
                DataAccessSecurityService::PERMISSION_READ,
            )) {
                continue;
            }
            $pageIds[] = $pageId;
        }

        return $pageIds;
    }

    /**
     * @return list<string>
     */
    private function extractRoutePlaceholders(string $pathPattern): array
    {
        if (!preg_match_all('/\{([^}]+)\}/', $pathPattern, $matches)) {
            return [];
        }

        $names = [];
        foreach ($matches[1] as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                $names[$trimmed] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @return array<string, string>
     */
    private function decodeRouteRequirements(PageRoute $route): array
    {
        $raw = $route->getRequirements();
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }
}
