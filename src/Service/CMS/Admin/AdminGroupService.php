<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\Group;
use App\Entity\PageAclGroup;
use App\Entity\Page;
use App\Entity\UsersGroup;
use App\Repository\RoleDataAccessRepository;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Service\Security\DataAccessSecurityService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

class AdminGroupService extends BaseService
{

    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly LookupService $lookupService
    ) {
    }


    /**
     * Get filtered groups with permission-based access control
     * Includes proper caching with user scope
     * Uses RoleDataAccessRepository optimized methods
     *
     * @return array<string, mixed>
     */
    public function getFilteredGroups(int $userId, int $page = 1, int $pageSize = 20, ?string $search = null, ?string $sort = null, string $sortDirection = 'asc'): array
    {
        if ($page < 1) $page = 1;
        if ($pageSize < 1 || $pageSize > 100) $pageSize = 20;
        if (!in_array($sortDirection, ['asc', 'desc'])) $sortDirection = 'asc';

        // Create cache key based on user and parameters
        $cacheKey = "filtered_groups_{$userId}_{$page}_{$pageSize}_" . md5(($search ?? '') . ($sort ?? '') . $sortDirection);

        return $this->cache
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList(
                $cacheKey,
                fn() => $this->fetchFilteredGroupsFromRepository($userId, $page, $pageSize, $search, $sort, $sortDirection)
            );
    }

    /**
     * Check if user can access a specific group for a given permission
     */
    public function canAccessGroup(int $userId, int $groupId, int $permission): bool
    {
        return $this->dataAccessSecurityService->hasPermission(
            $userId,
            LookupService::RESOURCE_TYPES_GROUP,
            $groupId,
            $permission
        );
    }

    /**
     * Fetch filtered groups from repository with permission checking
     * Uses RoleDataAccessRepository optimized SQL queries with pagination
     *
     * @return array<string, mixed>
     */
    private function fetchFilteredGroupsFromRepository(int $userId, int $page, int $pageSize, ?string $search, ?string $sort, string $sortDirection): array
    {
        // Get resource type ID for groups
        $resourceTypeId = $this->lookupService->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_GROUP
        );

        if (!$resourceTypeId) {
            return [
                'groups' => [],
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'totalCount' => 0,
                    'totalPages' => 0,
                    'hasNext' => false,
                    'hasPrevious' => false
                ]
            ];
        }

        // Check if user is admin
        $isAdmin = $this->dataAccessSecurityService->userHasAdminRole($userId);

        // Use repository method for accessible groups (admin or filtered)
        return $this->roleDataAccessRepository->getAccessibleGroupsForUser($userId, $resourceTypeId, $page, $pageSize, $search, $sort, $sortDirection, $isAdmin);
    }

    /**
     * Get single group by ID with full details including ACLs and entity scope caching
     *
     * @return array<string, mixed>
     */
    public function getGroupById(int $groupId): array
    {
        $cacheKey = "group_{$groupId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId)
            ->getItem($cacheKey, function () use ($groupId) {
                $group = $this->entityManager->getRepository(Group::class)->find($groupId);
                if (!$group) {
                    throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
                }
                return $this->formatGroupForDetail($group);
            });
    }

    /**
     * List the members of a group for the admin Groups page "View members" modal.
     *
     * A missing group is a 404 (not an empty list) so the caller can tell
     * "group does not exist" apart from "group has no members" (which is []).
     *
     * Members are scoped to the same VISIBLE set as the users list
     * (`intern = false`, `id_status > 0`): a member list that surfaced internal
     * / system users the admin cannot see on the Users page would be
     * inconsistent between the two screens. The field set matches the frontend
     * IGroupMember shape (id/email/name/user_name/status/blocked).
     *
     * @return list<array{id: int, email: string|null, name: string|null, user_name: string|null, status: string|null, blocked: bool}>
     */
    public function getGroupMembers(int $groupId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId)
            ->getList("group_members_{$groupId}", function () use ($groupId) {
                $group = $this->entityManager->getRepository(Group::class)->find($groupId);
                if (!$group) {
                    throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
                }

                $members = [];
                foreach ($group->getUsers() as $user) {
                    // Same visibility filter as the users list, so the two
                    // screens never disagree about who exists.
                    if ($user->isIntern() || ($user->getIdStatus() ?? 0) <= 0) {
                        continue;
                    }

                    $members[] = [
                        'id' => (int) $user->getId(),
                        'email' => $user->getEmail(),
                        'name' => $user->getName(),
                        'user_name' => $user->getUserName(),
                        'status' => $user->getStatus()?->getLookupCode(),
                        'blocked' => (bool) $user->isBlocked(),
                    ];
                }

                // Stable order for the modal: by email, matching the users list default.
                usort($members, static fn(array $a, array $b): int => strcmp((string) $a['email'], (string) $b['email']));

                return $members;
            });
    }

    /**
     * Create new group
     *
     * @param array<string, mixed> $groupData
     * @return array<string, mixed>
     */
    public function createGroup(array $groupData): array
    {
        $this->entityManager->beginTransaction();

        try {
            $this->validateGroupData($groupData);

            $group = new Group();
            $group->setName($this->asString($groupData['name']));
            $group->setDescription($this->asString($groupData['description'] ?? ''));
            $group->setRequires2fa((bool) ($groupData['requires_2fa'] ?? false));

            if (isset($groupData['id_group_types'])) {
                $group->setIdGroupTypes($this->asIntOrNull($groupData['id_group_types']));
            }

            $this->entityManager->persist($group);
            $this->entityManager->flush();

            // Create initial ACLs if provided
            if (isset($groupData['acls']) && is_array($groupData['acls'])) {
                $this->updateGroupAclsInternal($group, $groupData['acls']);
            }

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'groups',
                $group->getId(),
                $group,
                'Group created: ' . $group->getName()
            );

            $this->entityManager->commit();

            // Invalidate cache after create
            $this->cache
                ->withCategory(CacheService::CATEGORY_GROUPS)
                ->invalidateAllListsInCategory();

            // If initial ACLs were assigned, invalidate per-user ACL caches for
            // any users in this group (usually none on create, but covered for
            // safety in case the group was pre-populated).
            if (isset($groupData['acls']) && is_array($groupData['acls'])) {
                $this->invalidateGroupAclCaches($group);
            }

            return $this->formatGroupForDetail($group);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Update existing group
     *
     * @param array<string, mixed> $groupData
     * @return array<string, mixed>
     */
    public function updateGroup(int $userId, int $groupId, array $groupData): array
    {
        // Check permission before any operations
        if (!$this->canAccessGroup($userId, $groupId, DataAccessSecurityService::PERMISSION_UPDATE)) {
            throw new ServiceException('Insufficient permissions to update group', Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->beginTransaction();

        try {
            $group = $this->entityManager->getRepository(Group::class)->find($groupId);
            if (!$group) {
                throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
            }

            if (isset($groupData['description'])) {
                $group->setDescription($this->asString($groupData['description']));
            }
            if (isset($groupData['requires_2fa'])) {
                $group->setRequires2fa((bool) $groupData['requires_2fa']);
            }
            if (isset($groupData['id_group_types'])) {
                $group->setIdGroupTypes($this->asIntOrNull($groupData['id_group_types']));
            }

            $this->entityManager->flush();

            // Update ACLs if provided
            if (isset($groupData['acls']) && is_array($groupData['acls'])) {
                $this->updateGroupAclsInternal($group, $groupData['acls']);
            }

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'groups',
                $group->getId(),
                $group,
                'Group updated: ' . $group->getName()
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific group
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_GROUPS)
                ->invalidateAllListsInCategory();
            
            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateAllListsInCategory();

            // If ACLs were updated, also invalidate the per-user ACL caches
            // for every user in this group so page permissions reflect the
            // new rules immediately.
            if (isset($groupData['acls']) && is_array($groupData['acls'])) {
                $this->invalidateGroupAclCaches($group);
            }

            return $this->formatGroupForDetail($group);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Delete group
     */
    public function deleteGroup(int $userId, int $groupId): void
    {
        // Check permission before any operations
        if (!$this->canAccessGroup($userId, $groupId, DataAccessSecurityService::PERMISSION_DELETE)) {
            throw new ServiceException('Insufficient permissions to delete group', Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->beginTransaction();

        try {
            $group = $this->entityManager->getRepository(Group::class)->find($groupId);
            if (!$group) {
                throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
            }

            // Check if group has users
            if (!$group->getUsersGroups()->isEmpty()) {
                throw new ServiceException('Cannot delete group with assigned users', Response::HTTP_CONFLICT);
            }

            // Log transaction before deletion
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'groups',
                $group->getId(),
                $group,
                'Group deleted: ' . $group->getName()
            );

            $this->entityManager->remove($group);
            $this->entityManager->flush();

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific group
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_GROUPS)
                ->invalidateAllListsInCategory();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Get group ACLs with entity scope caching
     *
     * @return list<array<string, mixed>>
     */
    public function getGroupAcls(int $groupId): array
    {
        $cacheKey = "group_acls_{$groupId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId)
            ->getItem($cacheKey, function () use ($groupId) {
                $group = $this->entityManager->getRepository(Group::class)->find($groupId);
                if (!$group) {
                    throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
                }

                /** @var list<PageAclGroup> $acls */
                $acls = $this->entityManager->getRepository(PageAclGroup::class)
                    ->createQueryBuilder('ag')
                    ->select('ag, p')
                    ->leftJoin('ag.page', 'p')
                    ->where('ag.group = :group')
                    ->setParameter('group', $group)
                    ->orderBy('p.keyword', 'asc')
                    ->getQuery()
                    ->getResult();

                return array_map([$this, 'formatAclForResponse'], $acls);
            });
    }

    /**
     * Update group ACLs (bulk update)
     *
     * @param list<array<string, mixed>> $aclsData
     * @return list<array<string, mixed>>
     */
    public function updateGroupAcls(int $groupId, array $aclsData): array
    {
        $this->entityManager->beginTransaction();

        try {
            $group = $this->entityManager->getRepository(Group::class)->find($groupId);
            if (!$group) {
                throw new ServiceException('Group not found', Response::HTTP_NOT_FOUND);
            }

            $this->updateGroupAclsInternal($group, $aclsData);

            // Log transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'page_acl_groups',
                $group->getId(),
                false,
                'Group ACLs updated: ' . $group->getName() . ' (' . count($aclsData) . ' ACLs)'
            );

            $this->entityManager->commit();

            // Invalidate entity-scoped cache for this specific group
            $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_GROUP, $groupId);
            $this->cache
                ->withCategory(CacheService::CATEGORY_GROUPS)
                ->invalidateAllListsInCategory();

            $this->cache
                ->withCategory(CacheService::CATEGORY_PERMISSIONS)
                ->invalidateAllListsInCategory();

            // Invalidate per-user ACL caches for every member of this group.
            // ACLService::hasAccess caches under ENTITY_SCOPE_USER, so bumping
            // only the group scope is not enough to refresh member permissions.
            $this->invalidateGroupAclCaches($group);

            return $this->getGroupAcls($groupId);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }


    /**
     * Format group for detail view
     *
     * @return array<string, mixed>
     */
    private function formatGroupForDetail(Group $group): array
    {
        $acls = $this->getGroupAcls((int) $group->getId());

        return [
            'id' => $group->getId(),
            'name' => $group->getName(),
            'description' => $group->getDescription(),
            'id_group_types' => $group->getIdGroupTypes(),
            'requires_2fa' => $group->isRequires2fa(),
            'users_count' => $group->getUsersGroups()->count(),
            'users' => array_map(function (UsersGroup $ug) {
                $user = $ug->getUser();
                return [
                    'id' => $user?->getId(),
                    'email' => $user?->getEmail(),
                    'name' => $user?->getName()
                ];
            }, $group->getUsersGroups()->toArray()),
            'acls' => $acls
        ];
    }

    /**
     * Format ACL for response
     *
     * @return array<string, mixed>
     */
    private function formatAclForResponse(PageAclGroup $acl): array
    {
        return [
            'page_id' => $acl->getPage()?->getId(),
            'page_keyword' => $acl->getPage()?->getKeyword(),
            'page_url' => $acl->getPage()?->getUrl(),
            'acl_select' => $acl->getAclSelect(),
            'acl_insert' => $acl->getAclInsert(),
            'acl_update' => $acl->getAclUpdate(),
            'acl_delete' => $acl->getAclDelete()
        ];
    }

    /**
     * Validate group data
     *
     * @param array<string, mixed> $data
     */
    private function validateGroupData(array $data): void
    {
        if (empty($data['name'])) {
            throw new ServiceException('Group name is required', Response::HTTP_BAD_REQUEST);
        }

        // Check for duplicate name
        $existingGroup = $this->entityManager->getRepository(Group::class)
            ->findOneBy(['name' => $data['name']]);
        if ($existingGroup) {
            throw new ServiceException('Group name already exists', Response::HTTP_CONFLICT);
        }
    }

    /**
     * Validate ACL data
     */
    private function validateAclData(mixed $data): void
    {
        if (!is_array($data) || !isset($data['page_id']) || !is_numeric($data['page_id'])) {
            throw new ServiceException('Valid page_id is required for ACL', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Internal method to update group ACLs (without transaction handling)
     *
     * @param array<array-key, mixed> $aclsData
     */
    private function updateGroupAclsInternal(Group $group, array $aclsData): void
    {
        // First, remove all existing ACL permissions for this group
        $existingAcls = $this->entityManager->getRepository(PageAclGroup::class)
            ->findBy(['group' => $group]);

        foreach ($existingAcls as $existingAcl) {
            $this->entityManager->remove($existingAcl);
        }

        // Flush the deletions first to avoid constraint violations
        $this->entityManager->flush();

        // Then, add only the ACL permissions that are passed in the request
        if (!empty($aclsData)) {
            // Validate all ACL data first
            foreach ($aclsData as $aclData) {
                $this->validateAclData($aclData);
            }

            // Batch load all pages in one query to avoid N+1.
            // All rows are arrays at this point (validateAclData enforces it).
            $pageIds = [];
            foreach ($aclsData as $aclData) {
                if (is_array($aclData)) {
                    $pageIds[] = $aclData['page_id'] ?? null;
                }
            }
            /** @var list<Page> $pages */
            $pages = $this->entityManager->getRepository(Page::class)
                ->createQueryBuilder('p')
                ->where('p.id IN (:pageIds)')
                ->setParameter('pageIds', $pageIds)
                ->getQuery()
                ->getResult();

            // Create a map for quick lookup
            $pageMap = [];
            foreach ($pages as $page) {
                $pageMap[(int) $page->getId()] = $page;
            }

            // Create ACLs
            foreach ($aclsData as $aclData) {
                if (!is_array($aclData)) {
                    continue;
                }
                $pageId = $this->asInt($aclData['page_id']);
                if (!isset($pageMap[$pageId])) {
                    throw new ServiceException('Page not found: ' . $pageId, Response::HTTP_NOT_FOUND);
                }

                // Create new ACL
                $acl = new PageAclGroup();
                $acl->setGroup($group);
                $acl->setPage($pageMap[$pageId]);
                $acl->setAclSelect((bool) ($aclData['acl_select'] ?? true));
                $acl->setAclInsert((bool) ($aclData['acl_insert'] ?? false));
                $acl->setAclUpdate((bool) ($aclData['acl_update'] ?? false));
                $acl->setAclDelete((bool) ($aclData['acl_delete'] ?? false));

                $this->entityManager->persist($acl);
            }
        }

        // Flush the insertions
        $this->entityManager->flush();
    }

    /**
     * Invalidate per-user ACL caches for every member of the given group and
     * bump each member's acl_version so downstream clients (frontend BFF) can
     * detect the change.
     *
     * ACLService::hasAccess caches results under ENTITY_SCOPE_USER in the
     * PERMISSIONS category. Only invalidating the group scope is not enough
     * to refresh member permissions; we must touch each member's user scope
     * explicitly and rotate their acl_version.
     */
    private function invalidateGroupAclCaches(Group $group): void
    {
        $memberUserIds = [];
        foreach ($group->getUsersGroups() as $membership) {
            /** @var UsersGroup $membership */
            $user = $membership->getUser();
            if ($user === null) {
                continue;
            }
            $userId = $user->getId();
            if ($userId === null) {
                continue;
            }

            $memberUserIds[$userId] = true;
            $user->bumpAclVersion();
        }

        if (empty($memberUserIds)) {
            return;
        }

        // Persist the new acl_version values for all affected users.
        $this->entityManager->flush();

        $userIds = array_keys($memberUserIds);

        // Bumping the user entity scope invalidates every cache entry scoped
        // to that user across all categories (ACLService::hasAccess keys its
        // results under ENTITY_SCOPE_USER, so this is the key step).
        $this->cache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_USER, $userIds);

        // Lists in the users/permissions categories may also be stale.
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }
}