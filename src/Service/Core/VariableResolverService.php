<?php

namespace App\Service\Core;

use App\Entity\Page;
use App\Entity\User;
use App\Repository\PageRepository;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CacheService $cache,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly PageRepository $pageRepository
    ) {
    }

    /**
     * Get all available variables for the current context
     *
     * @param int|null $userId User ID (optional, defaults to current user)
     * @param int $languageId Language ID for data retrieval
     * @param bool $includeGlobalVars Whether to include global variables from sh-global-values page
     * @return array Array of variable names to their actual values
     */
    public function getAllVariables(?int $userId = null, int $languageId = 1, bool $includeGlobalVars = true): array
    {
        // Get current user if not specified
        if ($userId === null) {
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? $user->getId() : null;
        }

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
            $userWithValidationCodes = $this->loadUserWithValidationCodes($currentUser->getId());
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
            $this->addGlobalVariables($variables);
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
            return $this->entityManager->createQueryBuilder()
                ->select('u', 'vc')
                ->from(User::class, 'u')
                ->leftJoin('u.validationCodes', 'vc')
                ->where('u.id = :userId')
                ->setParameter('userId', $userId)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get user groups for a user
     *
     * @param int $userId User ID
     * @return array Array of group names
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
     * Get platform (web/mobile) from request
     *
     * @param \Symfony\Component\HttpFoundation\Request|null $request
     * @return string Platform ('web' or 'mobile')
     */
    private function getPlatform(?\Symfony\Component\HttpFoundation\Request $request): string
    {
        $platform = 'web';

        if ($request && (
            $request->query->get('mobile') ||
            $request->request->get('mobile')
        )) {
            $platform = 'mobile';
        }

        return $platform;
    }

    /**
     * Add global variables from sh_global_values page to the variables array
     *
     * @param array &$variables Reference to variables array to update
     */
    private function addGlobalVariables(array &$variables): void
    {
        try {
            // Find the sh_global_values page
            $globalPage = $this->pageRepository->findOneBy(['keyword' => 'sh-global-values']);
            if (!$globalPage) {
                return;
            }

            // Get the global values from page fields
            $globalValuesJson = $this->getPageFieldValue($globalPage, 'global_values');
            if ($globalValuesJson) {
                $globalValues = json_decode($globalValuesJson, true);
                if (is_array($globalValues)) {
                    foreach ($globalValues as $key => $value) {
                        $variables['global.' . $key] = $value;
                    }
                }
            }
        } catch (\Exception $e) {
            // If there's an error getting global values, continue without them
        }
    }

    /**
     * Get page field value by field name
     *
     * @param object $page The page entity
     * @param string $fieldName The field name to look for
     * @return string|null The field value or null if not found
     */
    private function getPageFieldValue($page, string $fieldName): ?string
    {
        try {
            $conn = $this->entityManager->getConnection();
            $sql = "
                SELECT pft.content
                FROM pages_fields_translation pft
                INNER JOIN fields f ON pft.id_fields = f.id
                WHERE pft.id_pages = :pageId
                AND f.name = :fieldName
                AND pft.id_languages = 1
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('pageId', $page->getId(), \PDO::PARAM_INT);
            $stmt->bindValue('fieldName', $fieldName, \PDO::PARAM_STR);
            $result = $stmt->executeQuery();

            $row = $result->fetchAssociative();
            return $row ? $row['content'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
