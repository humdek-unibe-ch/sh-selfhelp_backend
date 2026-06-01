<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Core;

use App\Entity\Page;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\GlobalVariableService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use App\Service\Core\UserContextAwareService;

/**
 * Service for resolving variables used in conditions and content interpolation
 *
 * Consolidates variable resolution logic from ConditionService and SectionUtilityService
 * to provide a unified interface for getting all available variables.
 */
class VariableResolverService
{
    /**
     * Request-scoped memoization for getAllVariables().
     * Keys: "{userId}|{languageId}|{includeGlobalVars}". A typical page render
     * calls this once per section; without this cache every call ran user,
     * validation-code, and global-value queries.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memoizedAllVariables = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CacheService $cache,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly GlobalVariableService $globalVariableService
    ) {
    }

    /**
     * Get all available variables for the current context
     *
     * @param int|null $userId User ID (optional, defaults to current user)
     * @param int $languageId Language ID for data retrieval
     * @param bool $includeGlobalVars Whether to include global variables from sh-global-values page
     * @return array<string, mixed> Array of variable names to their actual values
     */
    public function getAllVariables(?int $userId = null, int $languageId = 1, bool $includeGlobalVars = true): array
    {
        // Get current user if not specified
        if ($userId === null) {
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? $user->getId() : null;
        }

        $memoKey = ($userId ?? 'anon') . '|' . $languageId . '|' . ($includeGlobalVars ? '1' : '0');
        if (isset($this->memoizedAllVariables[$memoKey])) {
            $cached = $this->memoizedAllVariables[$memoKey];
            // Refresh only the cheap/time-sensitive variables on each call so cached
            // values never drift noticeably in long-running requests.
            $cached['current_date'] = date('Y-m-d');
            $cached['current_datetime'] = date('Y-m-d H:i:s');
            $cached['current_time'] = date('H:i');
            return $cached;
        }

        /** @var array<string, mixed> $variables */
        $variables = [];

        // Get request for context-aware variables
        $request = $this->requestStack->getCurrentRequest();

        // User-related variables
        if ($userId) {
            $variables['user_group'] = $this->getUserGroups($userId);
            $variables['language'] = $this->getUserLanguageId($userId) ?? $languageId;
            $variables['last_login'] = $this->getUserLastLoginDate($userId) ?? '';
        } else {
            $variables['user_group'] = [];
            $variables['language'] = $languageId;
            $variables['last_login'] = '';
        }

        // Date/time variables
        $variables['current_date'] = date('Y-m-d');
        $variables['current_datetime'] = date('Y-m-d H:i:s');
        $variables['current_time'] = date('H:i');

        // Context variables
        $variables['page_keyword'] = $this->getCurrentPageKeyword($request);
        $variables['platform'] = $this->getPlatform($request);

        // Additional system variables (from SectionUtilityService)
        $currentUser = $this->userContextAwareService->getCurrentUser();
        $variables['user_name'] = $currentUser ? $currentUser->getUserName() : '';
        $variables['user_email'] = $currentUser ? $currentUser->getEmail() : '';

        // Get user validation code (active/unconsumed validation code)
        $variables['user_code'] = '';
        if ($currentUser) {
            // Ensure validation codes are loaded
            $userWithValidationCodes = $this->loadUserWithValidationCodes((int) $currentUser->getId());
            if ($userWithValidationCodes) {
                $validationCodes = $userWithValidationCodes->getValidationCodes();
                if (!$validationCodes->isEmpty()) {
                    // Get the first active (unconsumed) validation code, same as AdminUserService
                    $activeCode = $validationCodes->filter(fn($vc) => $vc->getConsumed() === null)->first();
                    $variables['user_code'] = $activeCode ? $activeCode->getCode() : '';
                }
            }
        }

        $variables['user_id'] = $currentUser ? $currentUser->getId() : '';
        $variables['project_name'] = 'SelfHelp'; // Default project name

        // Add global variables if requested
        if ($includeGlobalVars) {
            $variables = $this->addGlobalVariables($variables, $languageId);
        }

        // Add custom variables from request (supporting {{var}} syntax from frontend)
        // Only add plain versions to prevent duplication and confusion
        if ($request) {
            $allRequestData = array_merge(
                $request->query->all(),
                $request->request->all()
            );

            foreach ($allRequestData as $key => $value) {
                // Only add if not already defined to prevent overwriting system variables
                if (!isset($variables[$key])) {
                    $variables[$key] = $value;
                }
            }
        }

        $this->memoizedAllVariables[$memoKey] = $variables;
        return $variables;
    }

    /**
     * Load user with validation codes relationship
     *
     * @param int $userId User ID
     * @return User|null User entity with validation codes loaded
     */
    private function loadUserWithValidationCodes(int $userId): ?User
    {
        try {
            $result = $this->entityManager->createQueryBuilder()
                ->select('u', 'vc')
                ->from(User::class, 'u')
                ->leftJoin('u.validationCodes', 'vc')
                ->where('u.id = :userId')
                ->setParameter('userId', $userId)
                ->getQuery()
                ->getOneOrNullResult();

            return $result instanceof User ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get user groups for a user
     *
     * @param int $userId User ID
     * @return list<string> Array of group names
     */
    private function getUserGroups(int $userId): array
    {
        $cacheKey = "user_groups_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserGroupNames($userId);
            });
    }

    /**
     * Get user's language ID
     *
     * @param int $userId User ID
     * @return int|null Language ID or null if not found
     */
    private function getUserLanguageId(int $userId): ?int
    {
        $cacheKey = "user_language_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserLanguageId($userId);
            });
    }

    /**
     * Get user's last login date
     *
     * @param int $userId User ID
     * @return string|null Last login date or null if not found
     */
    private function getUserLastLoginDate(int $userId): ?string
    {
        $cacheKey = "user_last_login_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserLastLoginDate($userId);
            });
    }

    /**
     * Get current page keyword from request
     *
     * @param \Symfony\Component\HttpFoundation\Request|null $request
     * @return string Page keyword or empty string
     */
    private function getCurrentPageKeyword(?\Symfony\Component\HttpFoundation\Request $request): string
    {
        if (!$request) {
            return '';
        }

        try {
            $currentRoute = $this->router->match($request->getPathInfo());
            $pageId = $currentRoute['page_id'] ?? null;

            if ($pageId) {
                $page = $this->entityManager->getRepository(Page::class)->find($pageId);
                if ($page) {
                    return $page->getKeyword() ?? '';
                }
            }
        } catch (\Exception $e) {
            // Route matching failed, keep empty
        }

        return '';
    }

    /**
     * Get platform (web/mobile) from request.
     *
     * Resolution order (first match wins):
     *   1. `X-Client-Type` header ('mobile', 'web', 'mobile_and_web') —
     *      preferred for the mobile app.
     *   2. `?platform=…` query / form parameter — used by the mobile dev
     *      preview running in a browser.
     *   3. Legacy `?mobile` truthy flag — kept for the old Ionic app.
     *   4. Default: 'web'.
     *
     * `mobile_and_web` is normalised to `mobile` for condition evaluation —
     * the Mantine condition builder offers only the binary (web|mobile)
     * platform variable, and conditions written for it should match either
     * client when the page is flagged as universal.
     *
     * @param \Symfony\Component\HttpFoundation\Request|null $request
     * @return string Platform ('web' or 'mobile')
     */
    private function getPlatform(?\Symfony\Component\HttpFoundation\Request $request): string
    {
        if (!$request) {
            return 'web';
        }

        $header = $request->headers->get('X-Client-Type');
        if (is_string($header) && $header !== '') {
            $normalised = strtolower($header);
            if ($normalised === 'mobile' || $normalised === 'mobile_and_web') {
                return 'mobile';
            }
            if ($normalised === 'web') {
                return 'web';
            }
        }

        $queryPlatform = $request->query->get('platform') ?? $request->request->get('platform');
        if (is_string($queryPlatform) && $queryPlatform !== '') {
            $normalised = strtolower($queryPlatform);
            if ($normalised === 'mobile' || $normalised === 'mobile_and_web') {
                return 'mobile';
            }
            if ($normalised === 'web') {
                return 'web';
            }
        }

        if ($request->query->get('mobile') || $request->request->get('mobile')) {
            return 'mobile';
        }

        return 'web';
    }

    /**
     * Add global variables from sh_global_values page to the variables array
     *
     * @param array<string, mixed> $variables Variables array to extend
     * @param int $languageId Language ID for caching separation
     * @return array<string, mixed> Variables array including global values
     */
    private function addGlobalVariables(array $variables, int $languageId): array
    {
        try {
            // Use the centralized global variable service
            $globalVariables = $this->globalVariableService->getGlobalVariableValues($languageId);

            // Merge global variables into the variables array
            foreach ($globalVariables as $key => $value) {
                $variables[(string) $key] = $value;
            }
        } catch (\Exception $e) {
            // If there's an error getting global values, continue without them
        }

        return $variables;
    }
}
