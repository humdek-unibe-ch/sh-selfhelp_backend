<?php

namespace App\Service\Core;

use App\Entity\Group;
use App\Entity\ScheduledJob;
use App\Entity\UsersGroup;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Executes scheduled task jobs that add or remove user-group memberships.
 */
class TaskJobExecutorService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly UserRepository $userRepository
    ) {
    }

    /**
     * Execute a persisted task job against the scheduled job's user.
     *
     * @param ScheduledJob $job
     *   The scheduled task job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` when all configured task operations succeed, otherwise `false`.
     */
    public function execute(ScheduledJob $job, string $transactionBy): bool
    {
        $taskConfig = $job->getConfig()['task'] ?? [];
        $taskType = $taskConfig['task_type'] ?? null;
        $groupSpecs = $taskConfig['groups'] ?? [];
        $user = $job->getUser();

        if (!is_string($taskType) || !is_array($groupSpecs) || $user === null) {
            return false;
        }

        $success = true;
        $affectedGroupIds = [];

        foreach ($groupSpecs as $groupSpec) {
            $group = $this->resolveGroup($groupSpec);
            if (!$group) {
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_EXECUTE_TASK_FAIL,
                    $transactionBy,
                    'scheduledJobs',
                    $job->getId(),
                    false,
                    'Task job failed because group could not be resolved'
                );
                $success = false;
                continue;
            }

            $currentMembership = $this->findMembership($user->getId(), $group->getId());
            $membershipChanged = false;
            if ($taskType === 'add_group') {
                if ($currentMembership === null) {
                    $membership = new UsersGroup();
                    $membership->setUser($user);
                    $membership->setGroup($group);
                    $this->entityManager->persist($membership);
                    $membershipChanged = true;
                }
            } elseif ($taskType === 'remove_group') {
                if ($currentMembership !== null) {
                    $this->entityManager->remove($currentMembership);
                    $membershipChanged = true;
                }
            } else {
                $success = false;
                continue;
            }

            if ($membershipChanged) {
                $affectedGroupIds[] = $group->getId();
            }

            $this->transactionService->logTransaction(
                $success ? LookupService::TRANSACTION_TYPES_EXECUTE_TASK_OK : LookupService::TRANSACTION_TYPES_EXECUTE_TASK_FAIL,
                $transactionBy,
                'scheduledJobs',
                $job->getId(),
                false,
                sprintf('%s %s for user %d', $taskType, $group->getName(), $user->getId())
            );
        }

        // Bump the user's acl_version so downstream clients (frontend BFF) can
        // detect permission/group changes and invalidate their caches. Done
        // before flush so the new value is persisted in the same transaction.
        if (!empty($affectedGroupIds)) {
            $user->bumpAclVersion();
        }

        $this->entityManager->flush();

        if (!empty($affectedGroupIds)) {
            $this->invalidateUserGroupCaches($user->getId(), array_values(array_unique($affectedGroupIds)));
        }

        return $success;
    }

    /**
     * Invalidate user, permission and group caches after a group membership
     * change performed by a scheduled task job. Mirrors the behaviour of
     * AdminUserService::invalidateUserGroupCaches so the ACL cache does not
     * go stale when memberships are modified by jobs.
     */
    private function invalidateUserGroupCaches(int $userId, array $groupIds): void
    {
        // Bumping the user entity scope invalidates every cache entry scoped
        // to that user across all categories (ACLService::hasAccess keys its
        // results under ENTITY_SCOPE_USER, so this is the key step).
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);

        // Lists in the users/permissions categories may also be stale.
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();

        // Groups category: the touched groups' member lists changed.
        if (!empty($groupIds)) {
            $groupCache = $this->cache->withCategory(CacheService::CATEGORY_GROUPS);
            $groupCache->invalidateEntityScopes(CacheService::ENTITY_SCOPE_GROUP, $groupIds);
            $groupCache->invalidateAllListsInCategory();
        }
    }

    /**
     * Resolve a group entity from a numeric id or group name.
     *
     * @param mixed $groupSpec
     *   The group specification stored in task config.
     *
     * @return Group|null
     *   The resolved group or `null` when it cannot be found.
     */
    private function resolveGroup(mixed $groupSpec): ?Group
    {
        if (is_numeric($groupSpec)) {
            return $this->entityManager->getRepository(Group::class)->find((int) $groupSpec);
        }

        if (is_string($groupSpec) && $groupSpec !== '') {
            return $this->entityManager->getRepository(Group::class)->findOneBy(['name' => $groupSpec]);
        }

        return null;
    }

    /**
     * Find an existing user-group membership.
     *
     * @param int $userId
     *   The user id.
     * @param int $groupId
     *   The group id.
     *
     * @return UsersGroup|null
     *   The membership entity, or `null` when none exists.
     */
    private function findMembership(int $userId, int $groupId): ?UsersGroup
    {
        return $this->entityManager->getRepository(UsersGroup::class)->findOneBy([
            'user' => $userId,
            'group' => $groupId,
        ]);
    }
}
