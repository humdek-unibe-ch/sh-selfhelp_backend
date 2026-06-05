<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Auth;

use App\Entity\Lookup;
use App\Entity\User;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\QueuedJobTimezoneAdjustmentService;
use App\Service\Core\TransactionService;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Service for handling user profile operations
 */
class ProfileService extends BaseService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly EntityManagerInterface $entityManager,
        private readonly QueuedJobTimezoneAdjustmentService $timezoneAdjustmentService
    ) {
    }

    /**
     * Update user name
     *
     * @param User $user The user entity
     * @param string $newName The new name
     * @return User The updated user entity
     */
    public function updateName(User $user, string $newName): User
    {
        return $this->executeInTransaction(function () use ($user, $newName) {
            // Fetch fresh managed entity to ensure proper change tracking
            $managedUser = $this->entityManager->find(User::class, $user->getId());
            if (!$managedUser) {
                throw new \InvalidArgumentException('User not found');
            }

            $oldName = $managedUser->getName();
            $managedUser->setName($newName);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $managedUser->getId(),
                false,
                json_encode([
                    'action' => 'name_changed',
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'user_email' => $managedUser->getEmail()
                ]) ?: null
            );

            // Invalidate user caches
            $this->invalidateUserCaches((int) $managedUser->getId());

            return $managedUser;
        });
    }

    /**
     * Update user timezone
     *
     * @param User $user The user entity
     * @param int $timezoneId The ID of the timezone lookup entry
     * @return User The updated user entity
     */
    public function updateTimezone(User $user, int $timezoneId): User
    {
        return $this->executeInTransaction(function () use ($user, $timezoneId) {
            // Fetch fresh managed entity to ensure proper change tracking
            $managedUser = $this->entityManager->find(User::class, $user->getId());
            if (!$managedUser) {
                throw new \InvalidArgumentException('User not found');
            }

            // Validate that the timezone lookup exists
            $timezone = $this->cache
                ->withCategory(CacheService::CATEGORY_LOOKUPS)
                ->getItem(
                    "lookup_by_id_{$timezoneId}",
                    function () use ($timezoneId) {
                        return $this->entityManager->getRepository(Lookup::class)->find($timezoneId);
                    }
                );

            if (!$timezone) {
                throw new \InvalidArgumentException('Invalid timezone ID');
            }

            // Validate that it's actually a timezone lookup
            if ($timezone->getTypeCode() !== 'timezones') {
                throw new \InvalidArgumentException('The provided ID is not a valid timezone');
            }

            $oldTimezoneId = $managedUser->getTimezone()?->getId();
            $managedUser->setTimezone($timezone);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $managedUser->getId(),
                false,
                json_encode([
                    'action' => 'timezone_changed',
                    'old_timezone_id' => $oldTimezoneId,
                    'new_timezone_id' => $timezoneId,
                    'new_timezone_code' => $timezone->getLookupCode(),
                    'new_timezone_value' => $timezone->getLookupValue(),
                    'user_email' => $managedUser->getEmail()
                ]) ?: null
            );

            // Slice T: recalculate this user's queued future wall-clock jobs so
            // their intended local delivery time is preserved in the new timezone.
            $this->timezoneAdjustmentService->adjustForUser(
                (int) $managedUser->getId(),
                (string) ($timezone->getLookupCode() ?? '')
            );

            // Invalidate user caches
            $this->invalidateUserCaches((int) $managedUser->getId());

            return $managedUser;
        });
    }

    /**
     * Update the user's communication (email/notification) delivery preferences.
     *
     * Scheduled email and notification jobs that respect user preferences are
     * skipped at delivery time when the matching flag is disabled (issue #29).
     *
     * @param User $user The user entity
     * @param bool $receivesNotifications Whether the user accepts push notifications
     * @param bool $receivesEmails Whether the user accepts platform emails
     * @return User The updated managed user entity
     */
    public function updateCommunicationPreferences(User $user, bool $receivesNotifications, bool $receivesEmails): User
    {
        return $this->executeInTransaction(function () use ($user, $receivesNotifications, $receivesEmails) {
            // Fetch fresh managed entity to ensure proper change tracking
            $managedUser = $this->entityManager->find(User::class, $user->getId());
            if (!$managedUser) {
                throw new \InvalidArgumentException('User not found');
            }

            $oldReceivesNotifications = $managedUser->receivesNotifications();
            $oldReceivesEmails = $managedUser->receivesEmails();

            $managedUser->setReceivesNotifications($receivesNotifications);
            $managedUser->setReceivesEmails($receivesEmails);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $managedUser->getId(),
                false,
                json_encode([
                    'action' => 'communication_preferences_changed',
                    'old_receives_notifications' => $oldReceivesNotifications,
                    'new_receives_notifications' => $receivesNotifications,
                    'old_receives_emails' => $oldReceivesEmails,
                    'new_receives_emails' => $receivesEmails,
                    'user_email' => $managedUser->getEmail(),
                ]) ?: null
            );

            // Invalidate user caches (bumps acl_version so the BFF refreshes user-data)
            $this->invalidateUserCaches((int) $managedUser->getId());

            return $managedUser;
        });
    }

    /**
     * Update user password
     *
     * @param User $user The user entity
     * @param string $currentPassword The current password for verification
     * @param string $newPassword The new password
     * @throws \InvalidArgumentException If passwords are invalid
     */
    public function updatePassword(User $user, string $currentPassword, string $newPassword): void
    {
        // Verify current password
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new \InvalidArgumentException('Current password is incorrect');
        }

        $this->executeInTransaction(function () use ($user, $newPassword) {
            // Fetch fresh managed entity to ensure proper change tracking
            $managedUser = $this->entityManager->find(User::class, $user->getId());
            if (!$managedUser) {
                throw new \InvalidArgumentException('User not found');
            }

            // Hash and set new password
            $hashedPassword = $this->passwordHasher->hashPassword($managedUser, $newPassword);
            $managedUser->setPassword($hashedPassword);
            $this->entityManager->flush();

            // Log the transaction
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $managedUser->getId(),
                false,
                json_encode([
                    'action' => 'password_changed',
                    'user_email' => $managedUser->getEmail(),
                    'user_name' => $managedUser->getUserName()
                ]) ?: null
            );

            // Invalidate user caches
            $this->invalidateUserCaches((int) $managedUser->getId());
        });
    }

    /**
     * Delete user account
     *
     * @param User $user The user entity
     * @param string $emailConfirmation Email confirmation for safety
     * @throws \InvalidArgumentException If email confirmation doesn't match
     */
    public function deleteAccount(User $user, string $emailConfirmation): void
    {
        // Verify email confirmation matches user's email
        if (strtolower(trim($emailConfirmation)) !== strtolower(trim($user->getEmail() ?? ''))) {
            throw new \InvalidArgumentException('Email confirmation does not match your account email');
        }

        $this->executeInTransaction(function () use ($user) {
            // Fetch fresh managed entity to ensure proper change tracking
            $managedUser = $this->entityManager->find(User::class, $user->getId());
            if (!$managedUser) {
                throw new \InvalidArgumentException('User not found');
            }

            // Prevent deletion of system users
            if (in_array(strtolower($managedUser->getName() ?? ''), ['admin', 'tpf'])) {
                throw new \InvalidArgumentException('Cannot delete system accounts');
            }

            // Log the transaction before deletion
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $managedUser->getId(),
                false,
                json_encode([
                    'action' => 'account_deleted',
                    'user_email' => $managedUser->getEmail(),
                    'user_name' => $managedUser->getUserName(),
                    'deleted_at' => date('Y-m-d H:i:s')
                ]) ?: null
            );

            // Get user ID before deletion for cache invalidation
            $userId = (int) $managedUser->getId();

            // Delete the user (cascade will handle related entities)
            $this->entityManager->remove($managedUser);
            $this->entityManager->flush();

            // Invalidate user caches
            $this->invalidateUserCaches($userId);
        });
    }


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
     * Invalidate user-related caches and bump the user's acl_version so the
     * frontend BFF can detect ACL/permission changes and surgically invalidate
     * its navigation cache.
     *
     * @param int $userId The user ID
     */
    private function invalidateUserCaches(int $userId): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user !== null) {
            $user->bumpAclVersion();
            $this->entityManager->flush();
        }

        // Invalidate all user lists
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->invalidateAllListsInCategory();

        // Invalidate entity scope (affects all cache depending on this user)
        $this->cache
            ->withCategory(CacheService::CATEGORY_USERS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
    }
}
