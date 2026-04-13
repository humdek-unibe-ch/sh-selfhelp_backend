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
            if ($taskType === 'add_group') {
                if ($currentMembership === null) {
                    $membership = new UsersGroup();
                    $membership->setUser($user);
                    $membership->setGroup($group);
                    $this->entityManager->persist($membership);
                }
            } elseif ($taskType === 'remove_group') {
                if ($currentMembership !== null) {
                    $this->entityManager->remove($currentMembership);
                }
            } else {
                $success = false;
                continue;
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

        $this->entityManager->flush();
        $this->cache->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $user->getId());

        return $success;
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
