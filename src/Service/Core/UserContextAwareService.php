<?php

namespace App\Service\Core;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Repository\PageRepository;
use App\Service\ACL\ACLService;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\DataAccessSecurityService;

/**
 * User Context Aware Service - Dual Permission System Manager
 *
 * This service manages two separate but complementary permission systems:
 *
 * 1. **ACL System (Frontend/Website)**: For website user page access and form submissions
 *    - Uses: AclGroup entities
 *    - Purpose: Fine-grained page-level permissions for website users
 *    - Methods: checkAclAccess(), checkAclAccessById()
 *
 * 2. **Data Access Management (Admin/CMS)**: For admin role-based CRUD operations
 *    - Uses: RoleDataAccess entity with bitwise permissions
 *    - Purpose: Role-based data access control for CMS operations
 *    - Methods: checkAdminAccess(), checkAdminAccessById()
 *
 * SECURITY ARCHITECTURE:
 * - Frontend operations use ACL permissions (user-specific + group inheritance)
 * - Admin operations use Data Access permissions (role aggregation with BIT_OR)
 * - Admin users bypass ACL checks but are subject to Data Access restrictions
 * - All permission checks are audited in dataAccessAudit table
 *
 * USAGE GUIDELINES:
 * - Frontend services: Use checkAclAccess*() methods
 * - Admin services: Use checkAdminAccess*() methods
 * - Legacy checkAccess*() methods delegate to ACL for backward compatibility
 */
class UserContextAwareService extends BaseService
{
    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly ACLService $aclService,
        private readonly PageRepository $pageRepository,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
    ) {}

    /**
     * Get the current authenticated user
     */
    public function getCurrentUser(): ?User
    {
        return $this->userContextService->getCurrentUser();
    }


    /**
     * Check if the current user has ACL access to page (Frontend/Website Access)
     *
     * This method checks user ACL permissions for frontend page access and form submissions.
     * Uses the ACL system for fine-grained page-level permissions for website users.
     *
     * @param string $page_keyword The page keyword
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If the page is not found or access denied
     */
    public function checkAclAccess(string $page_keyword, string $permission = 'select'): void
    {
        $page = $this->pageRepository->findOneBy(['keyword' => $page_keyword]);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $page = $this->userContextService->getCache()
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getItem("page_{$page_keyword}", function () use ($page_keyword) {
                $page = $this->pageRepository->findOneBy(['keyword' => $page_keyword]);
                if (!$page) {
                    $this->throwNotFound('Page not found');
                }
                return $page;
            });

        $this->checkAclPermission($page->getId(), $permission);
    }

    /**
     * Check if the current user has admin access to page (Admin/CMS Access)
     *
     * This method checks admin role-based data access permissions for CMS operations.
     * Uses the Data Access Management system for CRUD permissions on pages and sections.
     *
     * @param string $page_keyword The page keyword
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If the page is not found or access denied
     */
    public function checkAdminAccess(string $page_keyword, string $permission = 'select'): void
    {
        $page = $this->pageRepository->findOneBy(['keyword' => $page_keyword]);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $page = $this->userContextService->getCache()
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->getItem("page_{$page_keyword}", function () use ($page_keyword) {
                $page = $this->pageRepository->findOneBy(['keyword' => $page_keyword]);
                if (!$page) {
                    $this->throwNotFound('Page not found');
                }
                return $page;
            });

        $this->checkAdminPermission($page->getId(), $permission);
    }

    /**
     * Check if the current user has admin access to page by ID (Admin/CMS Access)
     *
     * This method checks admin role-based data access permissions for CMS operations.
     * Uses the Data Access Management system for CRUD permissions on pages and sections.
     *
     * @param int $pageId The page ID
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If the page is not found or access denied
     */
    public function checkAdminAccessById(int $pageId, string $permission = 'select'): void
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $this->checkAdminPermission($pageId, $permission);
    }

    /**
     * Private helper method to check admin data access permissions
     *
     * Handles the common logic for admin permission checking including:
     * - Getting current user ID
     * - Converting permission string to bit flags
     * - Calling DataAccessSecurityService.hasPermission()
     *
     * @param int $resourceId The page/resource ID to check permissions for
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If access is denied
     */
    private function checkAdminPermission(int $resourceId, string $permission): void
    {
        // Get current user ID
        $user = $this->getCurrentUser();
        $userId = 1; // guest user
        if ($user) {
            $userId = $user->getId();
        }

        // Convert permission string to DataAccessSecurityService bit flags
        $permissionBit = match ($permission) {
            'select' => DataAccessSecurityService::PERMISSION_READ,
            'insert' => DataAccessSecurityService::PERMISSION_CREATE,
            'update' => DataAccessSecurityService::PERMISSION_UPDATE,
            'delete' => DataAccessSecurityService::PERMISSION_DELETE,
            default => DataAccessSecurityService::PERMISSION_READ,
        };

        // Check data access permission for pages resource
        if (!$this->dataAccessSecurityService->hasPermission($userId, 'pages', $resourceId, $permissionBit)) {
            $this->throwForbidden('Access denied');
        }
    }
    
    /**
     * Check if the current user has ACL access to page by ID (Frontend/Website Access)
     *
     * This method checks user ACL permissions for frontend page access and form submissions.
     * Uses the ACL system for fine-grained page-level permissions for website users.
     *
     * @param int $pageId The page ID
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If the page is not found or access denied
     */
    public function checkAclAccessById(int $pageId, string $permission = 'select'): void
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $this->checkAclPermission($pageId, $permission);
    }

    /**
     * Private helper method to check ACL permissions
     *
     * Handles the common logic for ACL permission checking including:
     * - Getting current user ID
     * - Calling ACLService.hasAccess()
     *
     * @param int $resourceId The page/resource ID to check permissions for
     * @param string $permission The permission to check ('select', 'insert', 'update', 'delete')
     * @throws ServiceException If access is denied
     */
    private function checkAclPermission(int $resourceId, string $permission): void
    {
        // Get current user ID
        $user = $this->getCurrentUser();
        $userId = 1; // guest user
        if ($user) {
            $userId = $user->getId();
        }

        // Check ACL permission for the resource
        if ($this->aclService instanceof ACLService) {
            if (!$this->aclService->hasAccess($userId, $resourceId, $permission)) {
                $this->throwForbidden('Access denied');
            }
        }
    }

}
