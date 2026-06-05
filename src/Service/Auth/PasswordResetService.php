<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Service\Core\BaseService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgot-password / recovery flow.
 *
 * Two steps, mirroring the account-validation flow:
 *   1. {@see requestReset()} — generate a one-time token, store it on the user
 *      and send the recovery email (`mail_recovery`).
 *   2. {@see resetPassword()} — consume the token and set the new password.
 *
 * The recovery email is built through {@see MailTemplateService} which tags it
 * `required_system`, so it is delivered even when the recipient disabled
 * platform emails (issue #29). Email content/sender are resolved by
 * {@see MailTemplateService}; never inline copy here.
 */
class PasswordResetService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly MailTemplateService $mailTemplateService,
        private readonly TransactionService $transactionService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
    ) {
    }

    /**
     * Request a password reset for an email address.
     *
     * Always succeeds silently: unknown or non-active accounts are ignored so
     * the endpoint cannot be used to enumerate registered emails. For a known
     * active account a one-time token is stored and the recovery email is sent
     * immediately.
     */
    public function requestReset(string $email): void
    {
        $email = trim($email);
        if ($email === '') {
            return;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            return;
        }

        // Only active accounts use the recovery flow; invited/not-yet-validated
        // accounts go through the validation link instead.
        if ($user->getStatus()?->getLookupCode() !== LookupService::USER_STATUS_ACTIVE) {
            return;
        }

        try {
            $token = bin2hex(random_bytes(16));
            $user->setToken($token);
            $this->entityManager->flush();

            $userId = (int) $user->getId();

            $resolved = $this->mailTemplateService->buildEmailConfig(
                MailTemplateDefaults::TYPE_RECOVERY,
                [
                    'user_name' => $user->getName() ?: $user->getEmail(),
                    'reset_url' => $this->buildResetUrl($userId, $token),
                ],
                ['recipient_emails' => $user->getEmail()],
                $this->resolveUserMailLocale($user)
            );

            $jobId = $this->jobSchedulerService->scheduleDirectEmailJob(
                $resolved,
                new \DateTime('now', new \DateTimeZone('UTC')),
                $userId
            );

            if (!$jobId) {
                $this->logger->error('Failed to schedule password-reset email', ['userId' => $userId]);
                return;
            }

            $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);

            $this->transactionService->logTransaction(
                'update',
                LookupService::TRANSACTION_BY_BY_SYSTEM,
                'users',
                $userId,
                false,
                json_encode([
                    'action' => 'password_reset_requested',
                    'email' => $user->getEmail(),
                    'job_id' => $jobId,
                ]) ?: null
            );
        } catch (\Throwable $e) {
            // Swallow — the caller must not learn whether the email exists.
            $this->logger->error('Failed to request password reset', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Consume a recovery token and set a new password.
     *
     * @return bool True on success, false when the token is missing/invalid.
     */
    public function resetPassword(int $userId, string $token, string $newPassword): bool
    {
        $token = trim($token);
        if ($userId <= 0 || $token === '' || $newPassword === '') {
            return false;
        }

        try {
            $this->entityManager->beginTransaction();

            $user = $this->entityManager->getRepository(User::class)->find($userId);
            $storedToken = $user?->getToken();
            if (!$user instanceof User || $storedToken === null || !hash_equals($storedToken, $token)) {
                $this->entityManager->rollback();
                return false;
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $user->setToken(null);
            $this->entityManager->flush();

            $this->transactionService->logTransaction(
                'update',
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $userId,
                false,
                json_encode([
                    'action' => 'password_reset_completed',
                    'email' => $user->getEmail(),
                ]) ?: null
            );

            $this->entityManager->commit();

            return true;
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to reset password', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildResetUrl(int $userId, string $token): string
    {
        $baseUrl = rtrim($this->frontendBaseUrl, '/');

        return $baseUrl . sprintf('/reset/%d/%s', $userId, $token);
    }

    private function resolveUserMailLocale(User $user): ?string
    {
        $locale = $user->getLanguage()?->getLocale();
        if (!is_string($locale)) {
            return null;
        }

        $locale = trim($locale);

        return $locale !== '' ? $locale : null;
    }
}
