<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS\Admin;

use App\Entity\User;
use App\Entity\Group;
use App\Entity\Role;
use App\Entity\ValidationCode;
use App\Entity\UsersGroup;
use App\Entity\Language;
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use App\Service\Auth\UserValidationService;
use App\Service\Security\DataAccessSecurityService;
use App\Exception\ServiceException;
use App\Service\Auth\JWTService;
use App\Service\Mercure\MercureTopicResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling user-related operations in the admin panel
 * ENTITY RULE
 */
class AdminUserService extends BaseService
{
    private const SYSTEM_USERS = ['admin', 'tpf'];
    private const MAX_PAGE_SIZE = 100;
    private const DEFAULT_PAGE_SIZE = 20;

    /**
     * Status buckets exposed by getUserStats() and accepted by the `status`
     * filter on the user list / export.
     *
     * PRECEDENCE: `blocked` wins over every status. A blocked user is counted
     * as `blocked` regardless of their status lookup, so the buckets are
     * mutually exclusive — a blocked+invited user appears under `blocked`
     * only, never under `invited`. The buckets describe "what should the admin
     * do about this user", and for a blocked+invited user the answer is
     * "unblock them first".
     *
     * NOT EXHAUSTIVE: the `userStatus` lookup group also seeds `interested`
     * and `auto_created` (legacy codes with no writer in this codebase). Users
     * holding those statuses are counted in `total` but in no bucket, so
     * `active + invited + blocked <= total`. Do not present the tiles as a
     * breakdown that must sum. There is no `locked` status: the constant
     * LookupService::USER_STATUS_LOCKED has no seeded lookup row and no
     * writer, so it is deliberately not a bucket.
     */
    private const STATUS_BUCKETS = [
        LookupService::USER_STATUS_ACTIVE,
        LookupService::USER_STATUS_INVITED,
    ];

    /** Bucket that is derived from the `blocked` column rather than a status lookup. */
    private const STATUS_BUCKET_BLOCKED = RoleDataAccessRepository::STATUS_BUCKET_BLOCKED;

    /** CSV header for the user export/import contract. */
    private const EXPORT_COLUMNS = ['id', 'email', 'name', 'user_name', 'status', 'blocked', 'groups', 'roles', 'last_login'];

    /** Required CSV header for user import. */
    private const IMPORT_COLUMNS = ['email', 'name', 'user_name', 'groups'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LookupService $lookupService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TransactionService $transactionService,
        private readonly UserValidationService $userValidationService,
        private readonly CacheService $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly JWTService $jwtService,
        private readonly HubInterface $mercureHub,
        private readonly MercureTopicResolver $mercureTopics,
        private readonly LoggerInterface $logger,
    ) {
    }


    /**
     * Get filtered users with permission-based access control
     * Includes proper caching with user scope
     * Uses RoleDataAccessRepository optimized methods
     *
     * @return array<string, mixed>
     */
    public function getFilteredUsers(int $userId, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE, ?string $search = null, ?string $sort = null, string $sortDirection = 'asc', ?string $status = null, ?int $groupId = null): array
    {
        [$page, $pageSize, $sortDirection] = $this->validatePaginationParams($page, $pageSize, $sortDirection);
        $status = $this->validateStatusBucket($status);

        // Create cache key based on user and parameters
        $cacheKey = $this->buildCacheKey('filtered_users', $userId, $page, $pageSize, $search, $sort, $sortDirection, $status, $groupId);

        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList(
                $cacheKey,
                fn() => $this->fetchFilteredUsersFromRepository($userId, $page, $pageSize, $search, $sort, $sortDirection, $status, $groupId)
            );
    }

    /**
     * Population counts for the admin Users page tiles.
     *
     * Scoped to the caller's VISIBLE user set (same `intern = false`,
     * `id_status > 0` and group-access rules as the list) so the tiles always
     * reconcile with the table underneath them: `total` equals the unfiltered
     * list's `totalCount` for the same admin. Deliberately ignores the current
     * search/status/group filters — the tiles describe the population the admin
     * can see, not the current page.
     *
     * See self::STATUS_BUCKETS for the precedence rule and why the buckets do
     * not necessarily sum to `total`.
     *
     * @return array<string, int>
     */
    public function getUserStats(int $userId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList(
                $this->buildCacheKey('user_stats', $userId, 0),
                fn() => $this->roleDataAccessRepository->countVisibleUsersByStatusBucket(
                    $this->resolveAccessibleGroupIds($userId),
                    self::STATUS_BUCKETS
                )
            );
    }

    /**
     * Delete several users, collecting per-user failures instead of aborting.
     *
     * Partial success is the contract: a single bad id must not fail the whole
     * request, so the admin's other selections still apply. Also used for the
     * single-delete case (a one-element array) so bulk and single behave
     * identically.
     *
     * @param array<mixed> $userIds
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function bulkDeleteUsers(int $currentUserId, array $userIds): array
    {
        return $this->processBulk(
            $userIds,
            function (int $userId) use ($currentUserId): void {
                // Guard the caller's own account: deleting yourself mid-session
                // is unrecoverable and never intentional in a bulk selection.
                if ($userId === $currentUserId) {
                    throw new ServiceException('Cannot delete your own account', Response::HTTP_FORBIDDEN);
                }

                $this->deleteUser($currentUserId, $userId);
            }
        );
    }

    /**
     * Add every listed user to every listed group.
     *
     * Idempotent — an existing membership is a success, not a failure, and
     * creates no duplicate row (assignGroupsToUser skips existing links).
     * Unknown group IDs fail the whole request: that is a caller bug, not a
     * per-user outcome.
     *
     * @param array<mixed> $userIds
     * @param array<mixed> $groupIds
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function bulkAddUsersToGroups(int $currentUserId, array $userIds, array $groupIds): array
    {
        $normalizedGroupIds = $this->normalizeIdList($groupIds);
        $this->assertGroupsExist($normalizedGroupIds);

        return $this->processBulk(
            $userIds,
            function (int $userId) use ($currentUserId, $normalizedGroupIds): void {
                if (!$this->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_UPDATE)) {
                    throw new ServiceException('Insufficient permissions to update user', Response::HTTP_FORBIDDEN);
                }

                $this->addGroupsToUser($userId, $normalizedGroupIds);
            }
        );
    }

    /**
     * Remove every listed user from every listed group.
     *
     * Mirror of bulkAddUsersToGroups(): same request shape, same
     * partial-success contract, opposite verb. Idempotent — a user who is not
     * in the group is a success, not a failure (removeGroupsFromUser() simply
     * finds no relationship to delete). Unknown group IDs fail the whole
     * request: that is a caller bug, not a per-user outcome.
     *
     * NO self-lockout guard, deliberately: admin access comes from the `admin`
     * ROLE (rel_roles_users), not from group membership — see
     * DataAccessSecurityService::userHasAdminRole(), which joins u.roles and
     * short-circuits hasPermission() before any group is consulted. So an
     * admin removing themselves from every group keeps full admin access and
     * can re-add themselves. Unlike bulk-delete (irreversible), this is
     * recoverable, so a guard would block a legitimate action for no safety
     * gain. If admin ever becomes group-derived, this needs revisiting.
     *
     * @param array<mixed> $userIds
     * @param array<mixed> $groupIds
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function bulkRemoveUsersFromGroups(int $currentUserId, array $userIds, array $groupIds): array
    {
        $normalizedGroupIds = $this->normalizeIdList($groupIds);
        $this->assertGroupsExist($normalizedGroupIds);

        return $this->processBulk(
            $userIds,
            function (int $userId) use ($currentUserId, $normalizedGroupIds): void {
                if (!$this->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_UPDATE)) {
                    throw new ServiceException('Insufficient permissions to update user', Response::HTTP_FORBIDDEN);
                }

                $this->removeGroupsFromUser($userId, $normalizedGroupIds);
            }
        );
    }

    /**
     * Send the activation mail to several users.
     *
     * Reuses sendActivationMail() so bulk and single-user sends share one mail
     * path. An already-active user is reported as a failure with a readable
     * reason so the admin understands why the success count is lower than
     * their selection.
     *
     * @param array<mixed> $userIds
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function bulkSendActivationMail(int $currentUserId, array $userIds): array
    {
        return $this->processBulk(
            $userIds,
            function (int $userId) use ($currentUserId): void {
                if (!$this->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_UPDATE)) {
                    throw new ServiceException('Insufficient permissions to update user', Response::HTTP_FORBIDDEN);
                }

                $user = $this->findUserOrThrow($userId);
                if ($user->getStatus()?->getLookupCode() === LookupService::USER_STATUS_ACTIVE) {
                    throw new ServiceException('User is already active', Response::HTTP_BAD_REQUEST);
                }

                $this->sendActivationMail($userId);
            }
        );
    }

    /**
     * Rows for the CSV export, honouring the same filters as the list.
     *
     * Exports the full filtered set (no pagination) for the caller's visible
     * users.
     *
     * @return list<array<string, string>>
     */
    public function exportUsers(int $currentUserId, ?string $search = null, ?string $status = null, ?int $groupId = null): array
    {
        $status = $this->validateStatusBucket($status);

        $users = $this->roleDataAccessRepository->findVisibleUsersForExport(
            $this->resolveAccessibleGroupIds($currentUserId),
            $search,
            $status,
            $groupId
        );

        return array_map(fn(User $user): array => $this->formatUserForExport($user), $users);
    }

    /**
     * Import users from a parsed CSV.
     *
     * NOT atomic by design: valid rows import even when others fail, so a
     * single typo does not discard a large file. Imported users are created
     * WITHOUT an activation email (`enable_validation = false`) — a 200-row
     * import must not fire 200 unrecallable emails; the admin invites them
     * deliberately via the bulk send-activation action.
     *
     * @param list<array<string, string>> $rows       parsed data rows (header excluded)
     * @param int                         $rowOffset  file line number of the first data row
     * @return array{imported: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function importUsers(array $rows, int $rowOffset = 2): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            // Report the row number as the admin sees it in their spreadsheet
            // (1-based, header included) so they can find the bad line.
            $rowNumber = $rowOffset + $index;

            try {
                $email = trim($row['email'] ?? '');
                if ($email === '') {
                    throw new ServiceException('Email is required', Response::HTTP_BAD_REQUEST);
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new ServiceException('Invalid email address', Response::HTTP_BAD_REQUEST);
                }

                // An existing email is a skip, not an error: re-importing a
                // superset of an earlier file is a normal admin workflow.
                if ($this->userRepository->findOneByEmail($email) !== null) {
                    $skipped++;
                    continue;
                }

                $this->createUser([
                    'email' => $email,
                    'name' => $this->nullIfBlank($row['name'] ?? null),
                    'user_name' => $this->nullIfBlank($row['user_name'] ?? null),
                    'group_ids' => $this->resolveGroupNames($row['groups'] ?? null),
                    'enable_validation' => false,
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $this->describeFailure($e)];
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Check if user can access a specific user for a given permission
     */
    public function canAccessUser(int $userId, int $targetUserId, int $permission): bool
    {
        // Admin users bypass permission checks
        if ($this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return true;
        }

        // Get the target user and check all their groups
        $user = $this->entityManager->getRepository(User::class)->find($targetUserId);
        if (!$user) {
            return false;
        }

        // Check if user has permission to access ANY of the target user's groups
        foreach ($user->getUsersGroups() as $userGroup) {
            $groupId = $userGroup->getGroup()?->getId();
            if (
                $groupId !== null
                && $this->dataAccessSecurityService->hasPermission(
                    $userId,
                    LookupService::RESOURCE_TYPES_GROUP,
                    $groupId,
                    $permission
                )
            ) {
                return true; // Access granted if permission exists for any group
            }
        }

        return false; // No permission found for any of the user's groups
    }

    /**
     * Fetch filtered users from repository with permission checking
     * Uses RoleDataAccessRepository optimized SQL queries with pagination
     *
     * @return array<string, mixed>
     */
    private function fetchFilteredUsersFromRepository(int $userId, int $page, int $pageSize, ?string $search, ?string $sort, string $sortDirection, ?string $status = null, ?int $groupId = null): array
    {
        // Get resource type ID for groups (users are filtered via group access)
        $resourceTypeId = $this->lookupService->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_GROUP
        );

        if (!$resourceTypeId) {
            return [
                'users' => [],
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

        // Check if user is admin - use repository method for all users
        if ($this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return $this->roleDataAccessRepository->getAllUsersForAdmin($page, $pageSize, $search, $sort, $sortDirection, $status, $groupId);
        } else {
            // Use repository method for accessible users (via group permissions)
            return $this->roleDataAccessRepository->getAccessibleUsersForUser($userId, $resourceTypeId, $page, $pageSize, $search, $sort, $sortDirection, $status, $groupId);
        }
    }

    /**
     * Resolve the group scope for a caller: null for an admin (sees every
     * non-intern user), otherwise the groups they have access to. Mirrors the
     * branch in fetchFilteredUsersFromRepository() so stats/export and the
     * list agree on who is visible.
     *
     * @return list<int>|null
     */
    private function resolveAccessibleGroupIds(int $userId): ?array
    {
        if ($this->dataAccessSecurityService->userHasAdminRole($userId)) {
            return null;
        }

        $resourceTypeId = $this->lookupService->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_GROUP
        );

        if (!$resourceTypeId) {
            return [];
        }

        return $this->roleDataAccessRepository->getAccessibleGroupIdsForUser($userId, $resourceTypeId);
    }

    /**
     * Validate a `status` filter value against the supported buckets.
     *
     * Unknown values are a caller error (400) rather than a silently ignored
     * filter, which would show the admin an unfiltered list that contradicts
     * their selection.
     */
    private function validateStatusBucket(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $allowed = array_merge(self::STATUS_BUCKETS, [self::STATUS_BUCKET_BLOCKED]);
        if (!in_array($status, $allowed, true)) {
            throw new ServiceException(
                'Invalid status filter. Allowed values: ' . implode(', ', $allowed),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $status;
    }

    /**
     * Run a per-id operation, collecting successes and readable failures.
     *
     * Shared by the bulk endpoints so they report partial success identically.
     *
     * @param array<mixed>        $ids
     * @param callable(int): void $operation
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    private function processBulk(array $ids, callable $operation): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($this->normalizeIdList($ids) as $id) {
            try {
                $operation($id);
                $succeeded[] = $id;
            } catch (\Exception $e) {
                $failed[] = ['id' => $id, 'reason' => $this->describeFailure($e)];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Turn an exception into a message an admin can act on.
     *
     * ServiceException messages are already written for humans and are shown
     * verbatim. Anything else is an unexpected fault, so we log it and return
     * a generic line rather than leaking an exception class or stack detail
     * into the admin UI.
     */
    private function describeFailure(\Exception $e): string
    {
        if ($e instanceof ServiceException) {
            return $e->getMessage();
        }

        $this->logger->error('Unexpected failure during bulk user operation', [
            'exception' => $e->getMessage(),
        ]);

        return 'Unexpected error. Please try again or check the logs.';
    }

    /**
     * Fail the request when any group ID does not exist.
     *
     * @param list<int> $groupIds
     */
    private function assertGroupsExist(array $groupIds): void
    {
        if (empty($groupIds)) {
            throw new ServiceException('group_ids must not be empty', Response::HTTP_BAD_REQUEST);
        }

        $found = $this->batchLoadGroups($groupIds);
        $missing = array_values(array_diff($groupIds, array_keys($found)));

        if (!empty($missing)) {
            throw new ServiceException(
                'Unknown group_ids: ' . implode(', ', $missing),
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * Resolve `;`-separated group names from a CSV cell to group IDs.
     *
     * An unknown name errors the row: silently creating groups from a CSV
     * would let a typo spawn a permanent group.
     *
     * @return list<int>
     */
    private function resolveGroupNames(?string $groupNames): array
    {
        $names = array_values(array_filter(array_map('trim', explode(';', $groupNames ?? ''))));
        if (empty($names)) {
            return [];
        }

        /** @var list<Group> $groups */
        $groups = $this->entityManager->getRepository(Group::class)
            ->createQueryBuilder('g')
            ->where('g.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();

        $idsByName = [];
        foreach ($groups as $group) {
            $idsByName[(string) $group->getName()] = (int) $group->getId();
        }

        $missing = array_values(array_diff($names, array_keys($idsByName)));
        if (!empty($missing)) {
            throw new ServiceException(
                'Unknown group name(s): ' . implode(', ', $missing),
                Response::HTTP_BAD_REQUEST
            );
        }

        return array_values($idsByName);
    }

    /**
     * Format one user as a CSV export row.
     *
     * Group/role names are `;`-separated because `,` is the CSV delimiter.
     * `last_login` is ISO 8601 (empty when never), unlike the list view's
     * human-readable "N days ago" string, so the column stays machine-readable.
     *
     * @return array<string, string>
     */
    private function formatUserForExport(User $user): array
    {
        $groups = array_map(fn(Group $g): string => (string) $g->getName(), $user->getGroups()->toArray());
        $roles = array_map(fn(Role $r): string => (string) $r->getName(), $user->getUserRoles()->toArray());

        return [
            'id' => (string) $user->getId(),
            'email' => (string) $user->getEmail(),
            'name' => (string) $user->getName(),
            'user_name' => (string) $user->getUserName(),
            'status' => (string) $user->getStatus()?->getLookupCode(),
            'blocked' => $user->isBlocked() ? 'true' : 'false',
            'groups' => implode('; ', $groups),
            'roles' => implode('; ', $roles),
            'last_login' => $user->getLastLogin()?->format(\DateTimeInterface::ATOM) ?? '',
        ];
    }

    /**
     * Normalize an optional CSV cell to a trimmed string or null.
     */
    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim($value ?? '');
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The CSV columns for export, in contract order.
     *
     * @return list<string>
     */
    public static function exportColumns(): array
    {
        return self::EXPORT_COLUMNS;
    }

    /**
     * The CSV columns required for import.
     *
     * @return list<string>
     */
    public static function importColumns(): array
    {
        return self::IMPORT_COLUMNS;
    }

    /**
     * Get single user by ID with full details
     *
     * @return array<string, mixed>
     */
    public function getUserById(int $userId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem(
                "user_{$userId}",
                fn() => $this->formatUserForDetail($this->findUserOrThrow($userId))
            );
    }

    /**
     * Create new user
     * Every user created will automatically require validation unless specified otherwise
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function createUser(array $userData): array
    {
        $created = $this->executeInTransaction(function () use ($userData) {
            $this->validateUserData($userData, true);

            $user = $this->buildUserFromData(new User(), $userData);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Handle validation code (legacy)
            if (isset($userData['validation_code'])) {
                $this->handleValidationCode($user, $this->asString($userData['validation_code']));
            }

            // Handle relationships
            $this->handleUserRelationships($user, $userData);
            $this->entityManager->flush();

            // Setup validation
            $validationResult = $this->setupUserValidation($user, $userData);

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                $user,
                $this->buildCreateLogMessage($user, $validationResult)
            );

            // Get fresh data before invalidating caches
            $result = $this->formatUserForDetail($user, true);

            // Add validation info to response
            if ($validationResult) {
                $result['validation'] = $this->formatValidationResult($validationResult);
            }

            // Invalidate caches
            $this->invalidateUserCaches((int) $user->getId());

            return [
                'user' => $result,
                'validation' => $validationResult,
            ];
        });

        $result = $created['user'];
        $validationResult = $created['validation'] ?? null;

        if ($validationResult && isset($validationResult['job_id'])) {
            $emailSent = $this->userValidationService->executeScheduledValidationEmail($this->asInt($validationResult['job_id']));

            if ($emailSent) {
                $validationResult['message'] = 'Validation email sent successfully.';
            } else {
                $validationResult['message'] = 'Validation email queued, but immediate send failed. You can resend it from the admin user actions.';
                $this->logger->warning('Immediate validation email send failed after user creation', [
                    'userId' => $result['id'] ?? null,
                    'jobId' => $validationResult['job_id'],
                ]);
            }

            $result['validation'] = $this->formatValidationResult($validationResult);
        }

        return $result;
    }

    /**
     * Update existing user
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>
     */
    public function updateUser(int $currentUserId, int $userId, array $userData): array
    {
        // Check permission before any operations
        if (!$this->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_UPDATE)) {
            throw new ServiceException('Insufficient permissions to update user', Response::HTTP_FORBIDDEN);
        }

        return $this->executeInTransaction(function () use ($userId, $userData) {
            $user = $this->findUserOrThrow($userId);
            $this->validateUserData($userData, false, $user);

            $this->updateUserFromData($user, $userData);
            $this->handleUserRelationships($user, $userData);
            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $user,
                'User updated: ' . $user->getEmail()
            );

            // Get fresh data before invalidating caches
            $result = $this->formatUserForDetail($user, true);

            // Invalidate caches
            $this->invalidateUserCaches($userId);

            // Only invalidate related caches if the relationships were actually updated
            if (isset($userData['group_ids']) && is_array($userData['group_ids']) && !empty($userData['group_ids'])) {
                $groupCache = $this->cache->withCategory(CacheService::CATEGORY_GROUPS);
                $groupCache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_GROUP, $this->normalizeIdList($userData['group_ids']));
                $groupCache->invalidateAllListsInCategory();
            }

            if (isset($userData['role_ids']) && is_array($userData['role_ids']) && !empty($userData['role_ids'])) {
                $roleCache = $this->cache->withCategory(CacheService::CATEGORY_ROLES);
                $roleCache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_ROLE, $this->normalizeIdList($userData['role_ids']));
                $roleCache->invalidateAllListsInCategory();
            }

            return $result;
        });
    }

    /**
     * Delete user
     */
    public function deleteUser(int $currentUserId, int $userId): bool
    {
        // Check permission before any operations
        if (!$this->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_DELETE)) {
            throw new ServiceException('Insufficient permissions to delete user', Response::HTTP_FORBIDDEN);
        }

        return $this->executeInTransaction(function () use ($userId) {
            $user = $this->findUserOrThrow($userId);

            // Prevent deletion of system users
            if (in_array($user->getName(), self::SYSTEM_USERS)) {
                throw new ServiceException('Cannot delete system users', Response::HTTP_FORBIDDEN);
            }

            // Log transaction before deletion
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                $user,
                'User deleted: ' . $user->getEmail()
            );

            $this->entityManager->remove($user);
            $this->entityManager->flush();

            // Invalidate caches
            $this->invalidateUserCaches($userId);

            return true;
        });
    }

    /**
     * Block/Unblock user
     *
     * @return array<string, mixed>
     */
    public function toggleUserBlock(int $userId, bool $blocked): array
    {
        return $this->executeInTransaction(function () use ($userId, $blocked) {
            $user = $this->findUserOrThrow($userId);

            $user->setBlocked($blocked);
            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $user,
                'User ' . ($blocked ? 'blocked' : 'unblocked') . ': ' . $user->getEmail()
            );

            // Get fresh data before invalidating caches
            $result = $this->formatUserForDetail($user, true);

            // Invalidate caches
            $this->invalidateUserCaches($userId);

            return $result;
        });
    }

    /**
     * Get user groups
     *
     * @return list<array<string, mixed>>
     */
    public function getUserGroups(int $userId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem(
                "user_groups_{$userId}",
                fn() => $this->fetchUserGroups($userId)
            );
    }

    /**
     * Get user roles
     *
     * @return list<array<string, mixed>>
     */
    public function getUserRoles(int $userId): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem(
                "users_roles_{$userId}",
                fn() => $this->fetchUserRoles($userId)
            );
    }

    /**
     * Add groups to user
     */
    /**
     * @param array<mixed> $groupIds
     * @return list<array<string, mixed>>
     */
    public function addGroupsToUser(int $userId, array $groupIds): array
    {
        $groupIds = $this->normalizeIdList($groupIds);

        return $this->executeInTransaction(function () use ($userId, $groupIds) {
            $user = $this->findUserOrThrow($userId);

            $this->assignGroupsToUser($user, $groupIds, false);
            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $user,
                'Groups added to user: ' . $user->getEmail() . ' (Group IDs: ' . implode(', ', $groupIds) . ')',
                'rel_groups_users'
            );

            // Get fresh data before invalidating caches
            $result = $this->fetchUserGroupsFromEntity($user);

            // Invalidate caches
            $this->invalidateUserGroupCaches($userId, $groupIds);

            return $result;
        });
    }

    /**
     * Remove groups from user
     */
    /**
     * @param array<mixed> $groupIds
     * @return list<array<string, mixed>>
     */
    public function removeGroupsFromUser(int $userId, array $groupIds): array
    {
        $groupIds = $this->normalizeIdList($groupIds);

        return $this->executeInTransaction(function () use ($userId, $groupIds) {
            $user = $this->findUserOrThrow($userId);

            if (!empty($groupIds)) {
                $this->removeUserGroupRelationships($user, $groupIds);
            }

            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                $user,
                'Groups removed from user: ' . $user->getEmail() . ' (Group IDs: ' . implode(', ', $groupIds) . ')',
                'rel_groups_users'
            );

            // Get fresh data before invalidating caches
            $result = $this->fetchUserGroupsFromEntity($user);

            // Invalidate caches
            $this->invalidateUserGroupCaches($userId, $groupIds);

            return $result;
        });
    }

    /**
     * Add roles to user
     */
    /**
     * @param array<mixed> $roleIds
     * @return list<array<string, mixed>>
     */
    public function addRolesToUser(int $userId, array $roleIds): array
    {
        $roleIds = $this->normalizeIdList($roleIds);

        return $this->executeInTransaction(function () use ($userId, $roleIds) {
            $user = $this->findUserOrThrow($userId);

            $this->assignRolesToUser($user, $roleIds, false);
            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $user,
                'Roles added to user: ' . $user->getEmail() . ' (Role IDs: ' . implode(', ', $roleIds) . ')'
            );

            // Get fresh data before invalidating caches
            $result = $this->fetchUserRolesFromEntity($user);

            // Invalidate caches
            $this->invalidateUserRoleCaches($userId, $roleIds);

            return $result;
        });
    }

    /**
     * Remove roles from user
     */
    /**
     * @param array<mixed> $roleIds
     * @return list<array<string, mixed>>
     */
    public function removeRolesFromUser(int $userId, array $roleIds): array
    {
        $roleIds = $this->normalizeIdList($roleIds);

        return $this->executeInTransaction(function () use ($userId, $roleIds) {
            $user = $this->findUserOrThrow($userId);

            if (!empty($roleIds)) {
                $this->removeUserRoleRelationships($user, $roleIds);
            }

            $this->entityManager->flush();

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                $user,
                'Roles removed from user: ' . $user->getEmail() . ' (Role IDs: ' . implode(', ', $roleIds) . ')'
            );

            // Get fresh data before invalidating caches
            $result = $this->fetchUserRolesFromEntity($user);

            // Invalidate caches
            $this->invalidateUserRoleCaches($userId, $roleIds);

            return $result;
        });
    }

    /**
     * Send activation mail with new validation URL
     *
     * @return array<string, mixed>
     */
    public function sendActivationMail(int $userId): array
    {
        return $this->executeInTransaction(function () use ($userId) {
            $user = $this->findUserOrThrow($userId);

            // Use UserValidationService to resend validation email
            $validationResult = $this->userValidationService->resendValidationEmail($userId);

            if (!$validationResult['success']) {
                throw new ServiceException('Failed to send activation email: ' . $this->asString($validationResult['error']), Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Log transaction
            $this->logUserTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $user,
                'Activation email sent: ' . $user->getEmail() . ' (token: ' . $this->asString($validationResult['token']) . ', job_id: ' . $this->asString($validationResult['job_id']) . ')'
            );

            // Invalidate user list caches
            $this->cache
                ->withCategory(CacheService::CATEGORY_USERS)
                ->invalidateAllListsInCategory();

            return [
                'success' => true,
                'message' => 'Activation email sent successfully',
                'user_id' => $userId,
                'email' => $user->getEmail(),
                'token' => $validationResult['token'],
                'job_id' => $validationResult['job_id'],
                'validation_url' => $validationResult['validation_url']
            ];
        });
    }

    /**
     * Clean user data (placeholder)
     */
    public function cleanUserData(int $userId): bool
    {
        $user = $this->findUserOrThrow($userId);
        // TODO: Implement data cleaning logic
        return true;
    }

    /**
     * Start an impersonation session.
     *
     * Returns a short-lived JWT (RFC 8693 Token Exchange shape: `act.sub`
     * carries the original admin id) so downstream code can distinguish
     * impersonated sessions from real ones, while every authorisation
     * decision still runs as the *target* user.
     *
     * Authorisation rules (deny-by-default):
     *   1. Admin must be authenticated (controller already guarantees this).
     *   2. Target must exist and not be a protected system account.
     *   3. Admin cannot impersonate themselves.
     *   4. Target must not be blocked — there is no value in seeing what a
     *      blocked user "would" see, and it can hide the fact that the
     *      block has not yet propagated.
     *
     * Every successful start writes an entry to the `transactions` audit
     * trail; every failed attempt is logged via the standard exception
     * pipeline (status code + message) so the admin UI can surface a
     * specific reason.
     *
     * @throws ServiceException on any of the validation failures above
     * @return array<string, mixed>
     */
    public function impersonateUser(int $currentUserId, int $targetUserId): array
    {
        if ($currentUserId === $targetUserId) {
            throw new ServiceException(
                'Cannot impersonate yourself',
                Response::HTTP_BAD_REQUEST
            );
        }

        $targetUser = $this->findUserOrThrow($targetUserId);

        if (in_array($targetUser->getName(), self::SYSTEM_USERS, true)) {
            throw new ServiceException(
                'Cannot impersonate system users',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($targetUser->isBlocked()) {
            throw new ServiceException(
                'Cannot impersonate a blocked user',
                Response::HTTP_FORBIDDEN
            );
        }

        $tokenData = $this->jwtService->createImpersonationToken($targetUser, $currentUserId);

        // Audit trail. Logged against the *admin* (not the target) so the
        // `transactions.id_users` column points to the original actor.
        // We use the entity manager reference to avoid a second SELECT —
        // the user was already authenticated upstream so we know the row
        // exists; logUserTransaction only reads getId() / getName().
        $adminUser = $this->entityManager->getReference(\App\Entity\User::class, $currentUserId);
        assert($adminUser instanceof User);
        $this->logUserTransaction(
            LookupService::TRANSACTION_TYPES_UPDATE,
            $adminUser,
            sprintf(
                'Impersonation started: admin_id=%d -> target_id=%d (%s)',
                $currentUserId,
                $targetUserId,
                $targetUser->getEmail()
            )
        );

        // Real-time push to the target user's impersonation topic so any
        // session subscribed via the BFF SSE stream (the impersonating
        // tab as well as the target's own normal sessions, if any) flips
        // its banner state without polling. Best-effort — a hub outage
        // does NOT abort the impersonation start; the client-side
        // setTimeout(expires_in) safety-net still tears the banner down.
        $expiresAt = time() + $this->asInt($tokenData['expires_in']);
        $this->publishImpersonationEvent($targetUserId, [
            'active'         => true,
            'targetEmail'    => $targetUser->getEmail(),
            'targetUserId'   => $targetUserId,
            'adminUserId'    => $currentUserId,
            'expiresAt'      => $expiresAt,
            'expiresIn'      => $this->asInt($tokenData['expires_in']),
        ]);

        return [
            'impersonation_token' => $tokenData['access_token'],
            'expires_in'          => $tokenData['expires_in'],
            'target_email'        => $targetUser->getEmail(),
        ];
    }

    /**
     * Stop an impersonation session.
     *
     * Blacklists the supplied impersonation JWT so it cannot be replayed,
     * even if the cookie has leaked, and writes an audit entry against
     * the original admin (`adminUserId`).
     *
     * @param int    $adminUserId       the admin id recovered from the JWT's
     *                                  `act.sub` (or legacy `impersonated_by`)
     * @param int    $targetUserId      the user being impersonated (`id_users`)
     * @param string $impersonationToken the raw JWT we want to invalidate
     * @return array<string, mixed>
     */
    public function stopImpersonateUser(
        int $adminUserId,
        int $targetUserId,
        string $impersonationToken
    ): array {
        $this->jwtService->blacklistAccessToken($impersonationToken);

        // Audit log under the admin id (no SELECT needed — see impersonateUser).
        $adminUser = $this->entityManager->getReference(\App\Entity\User::class, $adminUserId);
        assert($adminUser instanceof User);
        $this->logUserTransaction(
            LookupService::TRANSACTION_TYPES_UPDATE,
            $adminUser,
            sprintf(
                'Impersonation stopped: admin_id=%d -> target_id=%d',
                $adminUserId,
                $targetUserId
            )
        );

        // Mercure push so every open tab (impersonating session AND the
        // target's normal sessions) clears the banner instantly — this
        // is what replaces the old 5-second cookie poll on the client.
        $this->publishImpersonationEvent($targetUserId, [
            'active'       => false,
            'targetUserId' => $targetUserId,
            'adminUserId'  => $adminUserId,
        ]);

        return ['stopped' => true];
    }

    /**
     * Publish an `impersonation-status` update to the target user's
     * Mercure topic.
     *
     * Wrapped in a try/catch on purpose: a Mercure publish failure must
     * never roll back the auditable JWT state change. The frontend has a
     * `setTimeout(expires_in)` safety-net for missed `active: false`
     * events, and a `visibilitychange` reconnection that re-subscribes
     * after sleep/network blips, so a single dropped publish degrades
     * gracefully into "banner disappears at TTL" instead of "banner
     * stuck forever".
     *
     * @param int                  $targetUserId user whose topic we publish to
     * @param array<string, mixed> $payload      will be JSON-encoded; must be UTF-8 safe
     */
    private function publishImpersonationEvent(int $targetUserId, array $payload): void
    {
        try {
            $this->mercureHub->publish(new Update(
                $this->mercureTopics->userImpersonationTopic($targetUserId),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                true,
                null,
                'impersonation-status'
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish impersonation-status to Mercure hub', [
                'target_user_id' => $targetUserId,
                'active'         => $payload['active'] ?? null,
                'exception'      => $e->getMessage(),
            ]);
        }
    }

    // Private helper methods

    /**
     * Execute operation within a database transaction
     */
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function executeInTransaction(callable $operation): mixed
    {
        $this->entityManager->beginTransaction();

        try {
            $result = $operation();
            $this->entityManager->commit();
            return $result;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Validate and normalize pagination parameters
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function validatePaginationParams(int $page, int $pageSize, string $sortDirection): array
    {
        $page = max(1, $page);
        // max(1, ...) is always >= 1, so the result is always truthy.
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $sortDirection = in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'asc';

        return [$page, $pageSize, $sortDirection];
    }

    /**
     * Build cache key from parameters
     *
     * @param mixed ...$params
     */
    private function buildCacheKey(string $prefix, mixed ...$params): string
    {
        $hashableParams = array_slice($params, 2); // Skip page and pageSize for hash
        $hashable = array_map(fn (mixed $p): string => $this->asString($p), $hashableParams);
        return $prefix . '_' . $this->asString($params[0] ?? '') . '_' . $this->asString($params[1] ?? '') . '_' . md5(implode('_', $hashable));
    }

    /**
     * Find user by ID or throw exception
     */
    private function findUserOrThrow(int $userId): User
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new ServiceException('User not found', Response::HTTP_NOT_FOUND);
        }
        return $user;
    }


    /**
     * Build user entity from data
     *
     * @param array<string, mixed> $userData
     */
    private function buildUserFromData(User $user, array $userData): User
    {
        $user->setEmail($this->asString($userData['email']));
        $user->setName($this->asStringOrNull($userData['name'] ?? null));
        $user->setUserName($this->asStringOrNull($userData['user_name'] ?? null));

        if (isset($userData['password'])) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $this->asString($userData['password']));
            $user->setPassword($hashedPassword);
        }

        if (isset($userData['blocked'])) {
            $user->setBlocked((bool) $userData['blocked']);
        }

        if (isset($userData['receives_notifications'])) {
            $user->setReceivesNotifications((bool) $userData['receives_notifications']);
        }

        if (isset($userData['receives_emails'])) {
            $user->setReceivesEmails((bool) $userData['receives_emails']);
        }

        $this->setUserRelatedEntities($user, $userData);

        return $user;
    }

    /**
     * Update user entity from data
     *
     * @param array<string, mixed> $userData
     */
    private function updateUserFromData(User $user, array $userData): void
    {
        if (isset($userData['email'])) {
            $user->setEmail($this->asString($userData['email']));
        }
        if (isset($userData['name'])) {
            $user->setName($this->asStringOrNull($userData['name']));
        }
        if (isset($userData['user_name'])) {
            $user->setUserName($this->asStringOrNull($userData['user_name']));
        }
        if (isset($userData['password']) && !empty($userData['password'])) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $this->asString($userData['password']));
            $user->setPassword($hashedPassword);
        }
        if (isset($userData['blocked'])) {
            $user->setBlocked((bool) $userData['blocked']);
        }
        if (isset($userData['receives_notifications'])) {
            $user->setReceivesNotifications((bool) $userData['receives_notifications']);
        }
        if (isset($userData['receives_emails'])) {
            $user->setReceivesEmails((bool) $userData['receives_emails']);
        }

        $this->setUserRelatedEntities($user, $userData);
    }

    /**
     * Set user related entities (language, user type)
     *
     * @param array<string, mixed> $userData
     */
    private function setUserRelatedEntities(User $user, array $userData): void
    {
        if (isset($userData['id_languages'])) {
            $language = $this->entityManager->getRepository(Language::class)->find($userData['id_languages']);
            $user->setLanguage($language);
        }

        if (isset($userData['user_type_id'])) {
            $userType = $this->lookupService->findById($this->asInt($userData['user_type_id']));
            if (!$userType || $userType->getTypeCode() !== LookupService::USER_TYPES) {
                throw new ServiceException('Invalid user type', Response::HTTP_BAD_REQUEST);
            }
            $user->setUserType($userType);
        } elseif (!$user->getUserType()) {
            // Set default user type for new users
            $defaultUserType = $this->lookupService->getDefaultUserType();
            if ($defaultUserType) {
                $user->setUserType($defaultUserType);
            }
        }
    }

    /**
     * Handle user relationships (groups and roles)
     *
     * @param array<string, mixed> $userData
     */
    private function handleUserRelationships(User $user, array $userData): void
    {
        if (isset($userData['group_ids']) && is_array($userData['group_ids'])) {
            $this->syncUserGroups($user, $this->normalizeIdList($userData['group_ids']));
        }

        if (isset($userData['role_ids']) && is_array($userData['role_ids'])) {
            $this->syncUserRoles($user, $this->normalizeIdList($userData['role_ids']));
        }
    }

    /**
     * Setup user validation if enabled
     *
     * @param array<string, mixed> $userData
     * @return array<string, mixed>|null
     */
    private function setupUserValidation(User $user, array $userData): ?array
    {
        $enableValidation = $userData['enable_validation'] ?? true;

        if (!$enableValidation) {
            return null;
        }

        $emailConfig = $userData['email_config'] ?? [];
        if (!is_array($emailConfig)) {
            $emailConfig = [];
        }
        /** @var array<string, mixed> $emailConfig */
        $validationResult = $this->userValidationService->setupUserValidation(
            $user,
            $emailConfig
        );

        if (!$validationResult['success']) {
            throw new ServiceException('Failed to setup user validation: ' . $this->asString($validationResult['error']), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $validationResult;
    }

    /**
     * Build log message for user creation
     *
     * @param array<string, mixed>|null $validationResult
     */
    private function buildCreateLogMessage(User $user, ?array $validationResult): string
    {
        $logMessage = 'User created: ' . $user->getEmail();

        if ($validationResult && $validationResult['success']) {
            $logMessage .= ' (with validation - token: ' . $this->asString($validationResult['token']) . ', job_id: ' . $this->asString($validationResult['job_id']) . ')';
        } elseif ($validationResult) {
            $logMessage .= ' (validation setup failed)';
        }

        return $logMessage;
    }

    /**
     * Format validation result for response
     *
     * @param array<string, mixed> $validationResult
     * @return array<string, mixed>
     */
    private function formatValidationResult(array $validationResult): array
    {
        return [
            'token' => $validationResult['token'],
            'job_id' => $validationResult['job_id'],
            'validation_url' => $validationResult['validation_url'],
            'message' => $validationResult['message']
        ];
    }

    /**
     * Fetch user groups
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUserGroups(int $userId): array
    {
        $user = $this->findUserOrThrow($userId);

        return array_values(array_map(function (Group $group) {
            return [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription()
            ];
        }, $user->getGroups()->toArray()));
    }

    /**
     * Fetch user roles
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUserRoles(int $userId): array
    {
        $user = $this->findUserOrThrow($userId);

        return array_values(array_map(function (Role $role) {
            return [
                'id' => $role->getId(),
                'name' => $role->getName(),
                'description' => $role->getDescription()
            ];
        }, $user->getUserRoles()->toArray()));
    }

    /**
     * Fetch user groups directly from entity (bypasses cache)
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUserGroupsFromEntity(User $user): array
    {
        return array_values(array_map(function (Group $group) {
            return [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription()
            ];
        }, $user->getGroups()->toArray()));
    }

    /**
     * Fetch user roles directly from entity (bypasses cache)
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUserRolesFromEntity(User $user): array
    {
        return array_values(array_map(function (Role $role) {
            return [
                'id' => $role->getId(),
                'name' => $role->getName(),
                'description' => $role->getDescription()
            ];
        }, $user->getUserRoles()->toArray()));
    }

    /**
     * Format user for list view
     *
     * @return array<string, mixed>
     */
    private function formatUserForList(User $user): array
    {
        $lastLogin = $user->getLastLogin();
        $lastLoginFormatted = 'never';
        if ($lastLogin) {
            $daysDiff = (new \DateTime())->diff($lastLogin)->days;
            $lastLoginFormatted = $lastLogin->format('Y-m-d') . ' (' . $daysDiff . ' days ago)';
        }

        $groups = array_map(fn(Group $g) => $g->getName(), $user->getGroups()->toArray());
        $roles = array_map(fn(Role $r) => $r->getName(), $user->getUserRoles()->toArray());

        // Get validation code
        $validationCode = $this->getValidationCode($user);

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'last_login' => $lastLoginFormatted,
            'status' => $user->getStatus()?->getLookupValue(),
            'blocked' => $user->isBlocked(),
            'receives_notifications' => $user->receivesNotifications(),
            'receives_emails' => $user->receivesEmails(),
            'code' => $validationCode,
            'groups' => implode('; ', $groups),
            'user_activity' => $user->getTransactions()->count(),
            'user_type_code' => $user->getUserType()?->getLookupCode(),
            'user_type' => $user->getUserType()?->getLookupValue(),
            'roles' => implode('; ', $roles)
        ];
    }

    /**
     * Format user for detail view
     *
     * @return array<string, mixed>
     */
    private function formatUserForDetail(User $user, bool $fresh = false): array
    {
        $basic = $this->formatUserForList($user);

        return array_merge($basic, [
            'user_name' => $user->getUserName(),
            'id_languages' => $user->getLanguage()?->getId(),
            'id_user_types' => $user->getUserType()?->getId(),
            'groups' => $fresh ? $this->fetchUserGroupsFromEntity($user) : $this->getUserGroups((int) $user->getId()),
            'roles' => $fresh ? $this->fetchUserRolesFromEntity($user) : $this->getUserRoles((int) $user->getId())
        ]);
    }

    /**
     * Get validation code for user
     */
    private function getValidationCode(User $user): string
    {
        if (in_array($user->getName(), self::SYSTEM_USERS)) {
            return $this->asString($user->getName());
        }

        $activeCode = $user->getValidationCodes()->filter(fn($vc) => $vc->getConsumed() === null)->first();
        return $activeCode ? $this->asString($activeCode->getCode()) : '-';
    }

    /**
     * Validate user data for create/update operations
     *
     * @param array<string, mixed> $data
     */
    private function validateUserData(array $data, bool $isCreate, ?User $existingUser = null): void
    {
        if ($isCreate && empty($data['email'])) {
            throw new ServiceException('Email is required', Response::HTTP_BAD_REQUEST);
        }

        $this->validateUniqueEmail($data, $existingUser);
        $this->validateUniqueUserName($data, $existingUser);
    }

    /**
     * Validate email uniqueness
     *
     * @param array<string, mixed> $data
     */
    private function validateUniqueEmail(array $data, ?User $existingUser): void
    {
        if (!isset($data['email'])) {
            return;
        }

        $existingUserWithEmail = $this->userRepository->findOneByEmail($this->asString($data['email']));
        if ($existingUserWithEmail && (!$existingUser || $existingUserWithEmail->getId() !== $existingUser->getId())) {
            throw new ServiceException('Email already exists', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Validate username uniqueness
     *
     * @param array<string, mixed> $data
     */
    private function validateUniqueUserName(array $data, ?User $existingUser): void
    {
        if (!isset($data['user_name']) || empty($data['user_name'])) {
            return;
        }

        $existingUserWithUserName = $this->userRepository->findOneBy(['user_name' => $data['user_name']]);
        if ($existingUserWithUserName && (!$existingUser || $existingUserWithUserName->getId() !== $existingUser->getId())) {
            throw new ServiceException('Username already exists', Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Handle validation code (legacy)
     */
    private function handleValidationCode(User $user, string $code): void
    {
        $existingCode = $this->entityManager->getRepository(ValidationCode::class)->find($code);

        if ($existingCode) {
            if ($existingCode->getConsumed()) {
                throw new ServiceException('Validation code already used', Response::HTTP_BAD_REQUEST);
            }
            $existingCode->setUser($user);
            $existingCode->setConsumed(new \DateTime());
        } else {
            $validationCode = new ValidationCode();
            $validationCode->setCode($code);
            $validationCode->setUser($user);
            $validationCode->setCreated(new \DateTime());
            $validationCode->setConsumed(new \DateTime());
            $this->entityManager->persist($validationCode);
        }
    }

    /**
     * Synchronize user groups - handles both creation and updates intelligently
     *
     * @param array<int, int> $groupIds
     */
    private function syncUserGroups(User $user, array $groupIds): void
    {
        if (empty($groupIds)) {
            // If no groups provided, remove all existing groups
            $this->removeAllUserGroups($user);
            return;
        }

        // Get current group IDs
        $currentGroupIds = array_map(fn($ug) => (int) $ug->getGroup()?->getId(), $user->getUsersGroups()->toArray());

        // Determine what needs to be added and removed
        $groupIdsToAdd = array_diff($groupIds, $currentGroupIds);
        $groupIdsToRemove = array_diff($currentGroupIds, $groupIds);

        // Remove groups that are no longer needed
        if (!empty($groupIdsToRemove)) {
            $this->removeUserGroupsByIds($user, $groupIdsToRemove);
        }

        // Add new groups
        if (!empty($groupIdsToAdd)) {
            $this->addUserGroupsByIds($user, $groupIdsToAdd);
        }
    }

    /**
     * Assign groups to user with optimized batch operations
     *
     * @param array<int, int> $groupIds
     */
    private function assignGroupsToUser(User $user, array $groupIds, bool $replace = true): void
    {
        if ($replace) {
            $this->removeAllUserGroups($user);
        }

        if (empty($groupIds)) {
            return;
        }

        $groups = $this->batchLoadGroups($groupIds);
        $existingUserGroups = $replace ? [] : $this->getExistingUserGroups($user, $groupIds);

        foreach ($groupIds as $groupId) {
            if (isset($groups[$groupId]) && !isset($existingUserGroups[$groupId])) {
                $userGroup = new UsersGroup();
                $userGroup->setUser($user);
                $userGroup->setGroup($groups[$groupId]);
                $this->entityManager->persist($userGroup);
            }
        }
    }

    /**
     * Remove all user groups
     */
    private function removeAllUserGroups(User $user): void
    {
        foreach ($user->getUsersGroups() as $userGroup) {
            $this->entityManager->remove($userGroup);
        }
        $user->getUsersGroups()->clear();
    }

    /**
     * Batch load groups to avoid N+1 queries
     *
     * @param array<int, int> $groupIds
     * @return array<int, Group>
     */
    private function batchLoadGroups(array $groupIds): array
    {
        /** @var list<Group> $groups */
        $groups = $this->entityManager->getRepository(Group::class)
            ->createQueryBuilder('g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[(int) $group->getId()] = $group;
        }

        return $groupMap;
    }

    /**
     * Get existing user groups to avoid duplicates
     *
     * @param array<int, int> $groupIds
     * @return array<int, true>
     */
    private function getExistingUserGroups(User $user, array $groupIds): array
    {
        /** @var list<UsersGroup> $existingUserGroupEntities */
        $existingUserGroupEntities = $this->entityManager->getRepository(UsersGroup::class)
            ->createQueryBuilder('ug')
            ->where('ug.user = :user')
            ->andWhere('ug.group IN (:groupIds)')
            ->setParameter('user', $user)
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        $existingUserGroups = [];
        foreach ($existingUserGroupEntities as $existingUg) {
            $existingUserGroups[(int) $existingUg->getGroup()?->getId()] = true;
        }

        return $existingUserGroups;
    }

    /**
     * Add user groups by IDs
     *
     * @param array<int, int> $groupIds
     */
    private function addUserGroupsByIds(User $user, array $groupIds): void
    {
        $groups = $this->batchLoadGroups($groupIds);

        foreach ($groupIds as $groupId) {
            if (isset($groups[$groupId])) {
                $userGroup = new UsersGroup();
                $userGroup->setUser($user);
                $userGroup->setGroup($groups[$groupId]);
                $this->entityManager->persist($userGroup);
            }
        }
    }

    /**
     * Remove user groups by IDs
     *
     * @param array<int, int> $groupIds
     */
    private function removeUserGroupsByIds(User $user, array $groupIds): void
    {
        /** @var list<UsersGroup> $userGroups */
        $userGroups = $this->entityManager->getRepository(UsersGroup::class)
            ->createQueryBuilder('ug')
            ->where('ug.user = :user')
            ->andWhere('ug.group IN (:groupIds)')
            ->setParameter('user', $user)
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        foreach ($userGroups as $userGroup) {
            $this->entityManager->remove($userGroup);
        }
    }

    /**
     * Remove user group relationships
     *
     * @param array<int, int> $groupIds
     */
    private function removeUserGroupRelationships(User $user, array $groupIds): void
    {
        $this->removeUserGroupsByIds($user, $groupIds);
    }

    /**
     * Synchronize user roles - handles both creation and updates intelligently
     *
     * @param array<int, int> $roleIds
     */
    private function syncUserRoles(User $user, array $roleIds): void
    {
        if (empty($roleIds)) {
            // If no roles provided, remove all existing roles
            foreach ($user->getUserRoles() as $role) {
                $user->removeRole($role);
            }
            return;
        }

        // Get current role IDs
        $currentRoleIds = array_map(fn($role) => (int) $role->getId(), $user->getUserRoles()->toArray());

        // Determine what needs to be added and removed
        $roleIdsToAdd = array_diff($roleIds, $currentRoleIds);
        $roleIdsToRemove = array_diff($currentRoleIds, $roleIds);

        // Remove roles that are no longer needed
        if (!empty($roleIdsToRemove)) {
            $rolesToRemove = $this->batchLoadRoles($roleIdsToRemove);
            foreach ($rolesToRemove as $role) {
                $user->removeRole($role);
            }
        }

        // Add new roles
        if (!empty($roleIdsToAdd)) {
            $rolesToAdd = $this->batchLoadRoles($roleIdsToAdd);
            foreach ($rolesToAdd as $role) {
                $user->addRole($role);
            }
        }
    }

    /**
     * Assign roles to user with optimized batch operations
     *
     * @param array<int, int> $roleIds
     */
    private function assignRolesToUser(User $user, array $roleIds, bool $replace = true): void
    {
        if ($replace) {
            foreach ($user->getUserRoles() as $role) {
                $user->removeRole($role);
            }
        }

        if (empty($roleIds)) {
            return;
        }

        $roles = $this->batchLoadRoles($roleIds);
        $existingRoleIds = $replace ? [] : $this->getExistingRoleIds($user);

        foreach ($roles as $role) {
            if (!isset($existingRoleIds[$role->getId()])) {
                $user->addRole($role);
            }
        }
    }

    /**
     * Batch load roles to avoid N+1 queries
     *
     * @param array<int, int> $roleIds
     * @return list<Role>
     */
    private function batchLoadRoles(array $roleIds): array
    {
        /** @var list<Role> $roles */
        $roles = $this->entityManager->getRepository(Role::class)
            ->createQueryBuilder('r')
            ->where('r.id IN (:roleIds)')
            ->setParameter('roleIds', $roleIds)
            ->getQuery()
            ->getResult();

        return $roles;
    }

    /**
     * Get existing role IDs for user
     *
     * @return array<int, true>
     */
    private function getExistingRoleIds(User $user): array
    {
        $existingRoleIds = [];
        foreach ($user->getUserRoles() as $role) {
            $existingRoleIds[(int) $role->getId()] = true;
        }
        return $existingRoleIds;
    }

    /**
     * Remove user role relationships
     *
     * @param array<int, int> $roleIds
     */
    private function removeUserRoleRelationships(User $user, array $roleIds): void
    {
        $roles = $this->batchLoadRoles($roleIds);

        foreach ($roles as $role) {
            $user->removeRole($role);
        }
    }

    /**
     * Log user transaction
     */
    private function logUserTransaction(string $transactionType, User $user, string $message, string $table = 'users'): void
    {
        $this->transactionService->logTransaction(
            $transactionType,
            LookupService::TRANSACTION_BY_BY_USER,
            $table,
            $user->getId(),
            $table === 'users' ? $user : false,
            $message
        );
    }

    /**
     * Invalidate user-related caches and bump the user's acl_version so the
     * frontend BFF can detect ACL/permission changes and surgically invalidate
     * its navigation cache.
     */
    private function invalidateUserCaches(int $userId): void
    {
        $this->bumpAclVersion($userId);

        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();

        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();

        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
    }

    /**
     * Rotate the user's acl_version column. Safe to call without an active
     * transaction: the caller's higher-level transaction covers persistence.
     */
    private function bumpAclVersion(int $userId): void
    {
        $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($userId);
        if ($user !== null) {
            $user->bumpAclVersion();
            $this->entityManager->flush();
        }
    }

    /**
     * Invalidate user and group caches
     *
     * @param array<int, int> $groupIds
     */
    private function invalidateUserGroupCaches(int $userId, array $groupIds): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();

        if (!empty($groupIds)) {
            $groupCache = $this->cache->withCategory(CacheService::CATEGORY_GROUPS);
            $groupCache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_GROUP, $groupIds);
            $groupCache->invalidateAllListsInCategory();
        }
    }

    /**
     * Invalidate user and role caches
     *
     * @param array<int, int> $roleIds
     */
    private function invalidateUserRoleCaches(int $userId, array $roleIds): void
    {
        $this->invalidateUserCaches($userId);

        $this->cache
            ->withCategory(CacheService::CATEGORY_ROLES)
            ->invalidateAllListsInCategory();

        if (!empty($roleIds)) {
            $roleCache = $this->cache->withCategory(CacheService::CATEGORY_ROLES);
            $roleCache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_ROLE, $roleIds);
            $roleCache->invalidateAllListsInCategory();
        }
    }

    /**
     * Normalize a loosely-typed list of IDs (typically decoded JSON request
     * input) into a clean re-indexed list of ints.
     *
     * @param array<mixed> $ids
     * @return list<int>
     */
    private function normalizeIdList(array $ids): array
    {
        return array_values(array_map(fn (mixed $id): int => $this->asInt($id), $ids));
    }
}
