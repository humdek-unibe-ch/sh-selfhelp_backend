<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\ACL;

use App\Entity\PageAclGroup;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\User;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Repository\AclRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ACL service
 * 
 * Handles access control list operations
 */
class ACLService
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly AclRepository $aclRepository,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * Check if user has access to a page
     *
     * @param int|string|null $userId The user ID or user identifier (may be null or string from Security)
     * @param int $pageId The page ID
     * @param string $accessType The type of access to check (select, insert, update, delete)
     * @return bool True if user has access, false otherwise
     */
    public function hasAccess(int|string|null $userId, int $pageId, string $accessType = 'select'): bool
    {
        // Normalize userId: null becomes the anonymous guest sentinel (which
        // has no group memberships, so only open-access pages resolve), strings
        // are coerced to int.
        $userId = $userId === null ? UserContextService::GUEST_USER_ID : (is_int($userId) ? $userId : (int) $userId);

        $cacheKey = "user_acl_{$pageId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId, $pageId, $accessType) {
                // Map accessType to column
                $modeMap = [
                    'select' => 'acl_select',
                    'insert' => 'acl_insert',
                    'update' => 'acl_update',
                    'delete' => 'acl_delete',
                ];
                if (!isset($modeMap[$accessType])) {
                    throw new \InvalidArgumentException("Unknown access type: $accessType");
                }
                $aclColumn = $modeMap[$accessType];

                // Get ACL for specific page using repository (cached)
                $results = $this->cache
                    ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                    ->getItem("user_acl_{$userId}_{$pageId}", fn() => $this->aclRepository->getUserAcl($userId, $pageId));

                // If no results or empty array, deny access
                if (empty($results)) {
                    return false;
                }

                // The repository returns an array of pages, but since we're querying for a specific page,
                // we should only have one result
                $result = $results[0];

                // If ACL column doesn't exist, deny access
                if (!array_key_exists($aclColumn, $result)) {
                    return false;
                }

                // Grant if column is 1
                $aclValue = $result[$aclColumn];
                return is_scalar($aclValue) && (int) $aclValue === 1;
            });
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

        // Use the repository to get all ACLs (cached)
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->getList("user_acl_{$userId}", fn() => $this->aclRepository->getUserAcl($userId));
    }

    /**
     * Add or update a group ACL for a page
     */
    public function addGroupAcl(Page $page, Group $group, bool $select, bool $insert, bool $update, bool $delete, EntityManagerInterface $em): void
    {
        $aclGroup = new PageAclGroup();
        $aclGroup->setGroup($group)
            ->setPage($page)
            ->setAclSelect($select)
            ->setAclInsert($insert)
            ->setAclUpdate($update)
            ->setAclDelete($delete);
        $em->persist($aclGroup);
        
        // Invalidate entity scopes for affected entities
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_GROUP, (int) $group->getId());
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());
        
        // Invalidate permission lists
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }

}
