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
 * Two steps:
 *   1. {@see requestReset()} — generate a one-time recovery token, store it in
 *      the dedicated {@see User::$password_reset_token} field together with a
 *      short UTC expiry ({@see RESET_TOKEN_TTL_SECONDS}), and send the recovery
 *      email (`mail_recovery`).
 *   2. {@see resetPassword()} — consume the (non-expired) token and set the new
 *      password.
 *
 * Reset tokens are deliberately stored SEPARATELY from the account-validation
 * token ({@see User::$token}); an outstanding invite and a reset request never
 * clobber each other (issue #32). A still-invited account that asks for a reset
 * is nudged back through the validation flow ({@see UserValidationService::resendValidationEmail()})
 * instead of receiving a reset link.
 *
 * The recovery email is built through {@see MailTemplateService} which tags it
 * `required_system`, so it is delivered even when the recipient disabled
 * platform emails (issue #29). Email content/sender are resolved by
 * {@see MailTemplateService}; never inline copy here.
 */
class PasswordResetService extends BaseService
{
    /** Recovery tokens are valid for one hour after they are issued. */
    public const RESET_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly MailTemplateService $mailTemplateService,
        private readonly TransactionService $transactionService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserValidationService $userValidationService,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
    ) {
    }

    /**
     * Request a password reset for an email address.
     *
     * Always succeeds silently: unknown accounts are ignored so the endpoint
     * cannot be used to enumerate registered emails. Behaviour by account state:
     *   - active: a one-time recovery token (with a 1-hour expiry) is stored in
     *     the dedicated reset fields and the recovery email is sent immediately.
     *   - invited / not-yet-validated: no reset link is issued; a fresh
     *     validation email is sent instead so the user can finish creating their
     *     account (issue #32).
     *   - any other state (locked, ...): ignored.
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

        $statusCode = $user->getStatus()?->getLookupCode();

        // A still-invited account has no usable password to recover. Re-send the
        // validation email (reusing the account-validation flow) rather than
        // leaking a reset link for an account that was never activated.
        if ($statusCode === LookupService::USER_STATUS_INVITED) {
            $this->resendValidationForInvitedUser($user);
            return;
        }

        // Only fully active accounts use the recovery flow.
        if ($statusCode !== LookupService::USER_STATUS_ACTIVE) {
            return;
        }

        try {
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->add(new \DateInterval('PT' . self::RESET_TOKEN_TTL_SECONDS . 'S'));
            $user->setPasswordResetToken($token);
            $user->setPasswordResetExpiresAt($expiresAt);
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
     * Re-send a validation email for an invited account that mistakenly asked
     * for a password reset. Swallows failures so the forgot-password endpoint
     * never reveals the account's state.
     */
    private function resendValidationForInvitedUser(User $user): void
    {
        try {
            $result = $this->userValidationService->resendValidationEmail((int) $user->getId());
            if (!($result['success'] ?? false)) {
                $this->logger->warning('Could not resend validation email during reset request', [
                    'userId' => $user->getId(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resend validation email during reset request', [
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
            if (!$user instanceof User) {
                $this->entityManager->rollback();
                return false;
            }

            $storedToken = $user->getPasswordResetToken();
            $expiresAt = $user->getPasswordResetExpiresAt();
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if (
                $storedToken === null
                || !hash_equals($storedToken, $token)
                || $expiresAt === null
                || $expiresAt < $now
            ) {
                $this->entityManager->rollback();
                return false;
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $user->setPasswordResetToken(null);
            $user->setPasswordResetExpiresAt(null);
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

            $this->sendPasswordChangedEmail($user);

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

    private function sendPasswordChangedEmail(User $user): void
    {
        $userId = (int) $user->getId();

        $resolved = $this->mailTemplateService->buildEmailConfig(
            MailTemplateDefaults::TYPE_PASSWORD_CHANGED,
            [
                'user_name' => $user->getName() ?: $user->getEmail(),
                'platform_url' => rtrim($this->frontendBaseUrl, '/') . '/',
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
            throw new \RuntimeException('Failed to schedule password-changed email');
        }

        if ($this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM) === false) {
            throw new \RuntimeException('Failed to send password-changed email');
        }
    }
}
