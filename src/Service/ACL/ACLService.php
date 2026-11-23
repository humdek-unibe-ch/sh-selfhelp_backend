<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\ACL;

use App\Entity\PageAclGroup;
use App\Entity\Group;
use App\Entity\Page;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\DataAccessSecurityService;
use App\Repository\AclRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACL service
 *
 * Handles access control list operations. Page ACL is one right: see the page
 * (and use its forms). The yes/no answer is never cached; the ACL rows from
 * {@see AclRepository::getUserAcl()} stay cached and are invalidated by scope.
 */
class ACLService
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly AclRepository $aclRepository,
        private readonly CacheService $cache,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
    ) {
    }

    /**
     * Check if user may see a page (group `select` / open access / admin role).
     *
     * @param int|string|null $userId The user ID or user identifier (may be null or string from Security)
     * @param int $pageId The page ID
     * @return bool True if user has access, false otherwise
     */
    public function hasAccess(int|string|null $userId, int $pageId): bool
    {
        // Normalize userId: null becomes the anonymous guest sentinel (which
        // has no group memberships, so only open-access pages resolve), strings
        // are coerced to int.
        $userId = $userId === null ? UserContextService::GUEST_USER_ID : (is_int($userId) ? $userId : (int) $userId);

        // Admin role always has full frontend page access. Group ACL rows are
        // still written for the admin group on create/import, but relying on
        // role membership matches the documented contract ("admins always have
        // access") and covers operators whose account has the admin role without
        // an up-to-date admin-group ACL snapshot / cache entry.
        if ($userId > 0 && $this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return true;
        }

        // Do not cache this boolean (F01 / rule 3 reading 5). Cache the rows it
        // is computed from via AclRepository::getUserAcl (user + page scopes).
        $results = $this->aclRepository->getUserAcl($userId, $pageId);

        if ($results === []) {
            return false;
        }

        $result = $results[0];
        if (!array_key_exists('acl_select', $result)) {
            return false;
        }

        $aclValue = $result['acl_select'];
        return is_scalar($aclValue) && (int) $aclValue === 1;
    }

    /**
     * Get all pages with ACL information for a user
     *
     * This is cached in memory for the duration of the request
     * so it's efficient to call multiple times
     *
     * @param int|string|null $userId The user ID
     * @return list<array<string, mixed>> Array of pages with ACL information
     */
    public function getAllUserAcls(int|string|null $userId): array
    {
        // Handle null or non-integer userId
        if ($userId === null) {
            $userId = UserContextService::GUEST_USER_ID; // anonymous guest sentinel
        } elseif (!is_int($userId)) {
            // Convert string user ID to int if needed
            $userId = (int) $userId;
        }

        // The admin role may open every page by its address (hasAccess), so the
        // menus, the search and the page lists show every page too: one answer
        // to "may this caller see the page?" (F89). For everyone else the
        // groups decide, through the stored procedure.
        if ($userId > 0 && $this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->getList("user_acl_admin_{$userId}", fn() => $this->aclRepository->getFullAccessToAllPages($userId));
        }

        // Use the repository to get all ACLs (cached)
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getList("user_acl_{$userId}", fn() => $this->aclRepository->getUserAcl($userId));
    }

    /**
     * Add or update a group ACL for a page. A row means the group may see the page.
     */
    public function addGroupAcl(Page $page, Group $group, EntityManagerInterface $em, bool $select = true): void
    {
        $aclGroup = new PageAclGroup();
        $aclGroup->setGroup($group)
            ->setPage($page)
            ->setAclSelect($select);
        $em->persist($aclGroup);

        // Invalidate entity scopes for affected entities
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_GROUP, (int) $group->getId());
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());

        // Clear the permission LISTS only (user ACL snapshots are lists and not
        // user-scoped keys that the group/page scopes above reach), never the
        // whole category generation: that wiped every permission item of every
        // user on each page create or import (F27).
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }

}
