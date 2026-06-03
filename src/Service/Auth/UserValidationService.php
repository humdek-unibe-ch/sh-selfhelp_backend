<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\BaseService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for user account validation flows.
 *
 * Handles:
 *   - Token validation and account activation.
 *   - Validation email scheduling and resending.
 *   - Welcome email after successful validation.
 *   - 2FA verification-code emails on login.
 *
 * Email content is resolved by {@see MailTemplateService}; this service only
 * supplies runtime variables (`user_name`, `code`, `validation_url`, etc.) and
 * the recipient address. All hardcoded sender/template defaults live in
 * {@see MailTemplateDefaults} — never inline here.
 */
class UserValidationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly TransactionService $transactionService,
        private readonly LoggerInterface $logger,
        private readonly LookupService $lookupService,
        private readonly MailTemplateService $mailTemplateService,
        private readonly string $frontendBaseUrl,
    ) {
    }

    /**
     * Setup validation for an existing user (generates token and schedules email).
     *
     * @param User                 $user        The user entity.
     * @param array<string, mixed> $emailConfig Optional email configuration overrides.
     * @return array<string, mixed> Result with token and job ID.
     */
    public function setupUserValidation(User $user, array $emailConfig = []): array
    {
        if ($user->getStatus()?->getLookupCode() === LookupService::USER_STATUS_ACTIVE) {
            return ['success' => false, 'error' => 'Account is already active.'];
        }

        try {
            $token = $this->generateValidationToken();
            $user->setToken($token);

            $status = $this->lookupService->findByTypeAndCode(LookupService::USER_STATUS, LookupService::USER_STATUS_INVITED);
            $user->setStatus($status);

            if ($user->isBlocked() === null) {
                $user->setBlocked(true);
            }

            $job = $this->scheduleValidationEmail((int) $user->getId(), $token, $emailConfig);

            if (!$job) {
                throw new \RuntimeException('Failed to schedule validation email');
            }

            return [
                'success' => true,
                'token' => $token,
                'job_id' => $job->getId(),
                'validation_url' => $this->buildValidationUrl((int) $user->getId(), $token),
                'message' => 'Validation email has been queued.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to setup user validation', [
                'error' => $e->getMessage(),
                'userId' => $user->getId(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate a token and activate the user account.
     *
     * @param int    $userId User ID.
     * @param string $token  Validation token.
     * @return array<string, mixed> Result of validation.
     */
    public function validateToken(int $userId, string $token): array
    {
        try {
            $this->entityManager->beginTransaction();

            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user) {
                $this->entityManager->rollback();
                return [
                    'success' => false,
                    'error' => 'User not found',
                ];
            }

            $activeStatus = $this->lookupService->findByTypeAndCode(LookupService::USER_STATUS, LookupService::USER_STATUS_ACTIVE);
            if ($user->getStatus()?->getLookupCode() === LookupService::USER_STATUS_ACTIVE) {
                $this->entityManager->rollback();
                return [
                    'success' => false,
                    'error' => 'Account is already active.',
                ];
            }

            if ($user->getToken() !== $token) {
                $this->entityManager->rollback();
                return [
                    'success' => false,
                    'error' => 'Invalid validation token',
                ];
            }

            $user->setBlocked(false);
            $user->setToken(null);
            if ($activeStatus) {
                $user->setStatus($activeStatus);
            }

            $this->entityManager->flush();

            $welcomeJobId = $this->scheduleWelcomeEmail($userId);

            $this->transactionService->logTransaction(
                'update',
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $userId,
                false,
                json_encode([
                    'action' => 'account_validated',
                    'token' => $token,
                    'email' => $user->getEmail(),
                    'welcome_job_id' => $welcomeJobId,
                ]) ?: null
            );

            $this->entityManager->commit();

            return [
                'success' => true,
                'message' => 'Account successfully validated',
                'user_id' => $userId,
                'welcome_job_id' => $welcomeJobId,
            ];
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to validate token', [
                'userId' => $userId,
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Validation failed due to system error',
            ];
        }
    }

    /**
     * Resend validation email for a user.
     *
     * @param int                  $userId      User ID.
     * @param array<string, mixed> $emailConfig Caller-supplied email config overrides (win over CMS).
     * @return array<string, mixed> Result of resend operation.
     */
    public function resendValidationEmail(int $userId, array $emailConfig = []): array
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'User not found',
                ];
            }

            if ($user->getStatus()?->getLookupCode() === LookupService::USER_STATUS_ACTIVE) {
                return ['success' => false, 'error' => 'Account is already active.'];
            }

            $token = $this->generateValidationToken();
            $user->setToken($token);
            $this->entityManager->flush();

            $job = $this->scheduleValidationEmail($userId, $token, $emailConfig);
            if (!$job) {
                return [
                    'success' => false,
                    'error' => 'Failed to schedule validation email',
                ];
            }

            $executed = $this->jobSchedulerService->executeJob(
                (int) $job->getId(),
                LookupService::TRANSACTION_BY_BY_SYSTEM
            );

            if ($executed === false) {
                $this->logger->error('Failed to execute validation email job', [
                    'userId' => $userId,
                    'jobId' => $job->getId(),
                ]);
                return [
                    'success' => false,
                    'error' => 'Failed to send validation email',
                ];
            }

            return [
                'success' => true,
                'message' => 'Validation email resent successfully',
                'token' => $token,
                'job_id' => $job->getId(),
                'validation_url' => $this->buildValidationUrl($userId, $token),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resend validation email', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to resend validation email',
            ];
        }
    }

    /**
     * Schedule and immediately send a 2FA verification-code email.
     *
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function send2faEmail(int $userId, int $code): bool
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->logger->error('User not found for 2FA email', ['userId' => $userId]);
            return false;
        }

        $emailConfig = $this->mailTemplateService->buildEmailConfig(
            MailTemplateDefaults::TYPE_2FA,
            [
                'user_name' => $user->getName() ?: $user->getEmail(),
                'code'      => (string) $code,
            ],
            [
                'recipient_emails' => $user->getEmail(),
            ],
            $this->resolveUserMailLocale($user)
        );

        $jobId = $this->jobSchedulerService->scheduleDirectEmailJob(
            $emailConfig,
            new \DateTime('now', new \DateTimeZone('UTC')),
            $userId
        );

        if (!$jobId) {
            $this->logger->error('Failed to schedule 2FA email', ['userId' => $userId]);
            return false;
        }

        return $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM) !== false;
    }

    public function executeScheduledValidationEmail(int $jobId): bool
    {
        return $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM) !== false;
    }

    private function generateValidationToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Schedule a validation email for a user.
     *
     * @param array<string, mixed> $emailConfig Caller overrides (win over CMS template).
     */
    private function scheduleValidationEmail(int $userId, string $token, array $emailConfig = []): ScheduledJob|false
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->logger->error('User not found for validation email', ['userId' => $userId]);
            return false;
        }

        $resolved = $this->mailTemplateService->buildEmailConfig(
            MailTemplateDefaults::TYPE_CONFIRM,
            [
                'user_name'       => $user->getName() ?: $user->getEmail(),
                'validation_url'  => $this->buildValidationUrl($userId, $token),
            ],
            array_merge(
                ['recipient_emails' => $user->getEmail()],
                $emailConfig
            ),
            $this->resolveUserMailLocale($user)
        );

        return $this->jobSchedulerService->scheduleUserValidationEmail($userId, $token, $resolved);
    }

    /**
     * Schedule an immediate welcome email after successful validation.
     *
     * @param array<string, mixed> $emailConfig Caller overrides (win over CMS template).
     */
    private function scheduleWelcomeEmail(int $userId, array $emailConfig = []): int|false
    {
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->logger->error('User not found for welcome email', ['userId' => $userId]);
            return false;
        }

        $resolved = $this->mailTemplateService->buildEmailConfig(
            MailTemplateDefaults::TYPE_WELCOME,
            [
                'user_name'    => $user->getName() ?: $user->getEmail(),
                'platform_url' => $this->buildPlatformUrl(),
            ],
            array_merge(
                ['recipient_emails' => $user->getEmail()],
                $emailConfig
            ),
            $this->resolveUserMailLocale($user)
        );

        try {
            $jobId = $this->jobSchedulerService->scheduleDirectEmailJob(
                $resolved,
                new \DateTime('now', new \DateTimeZone('UTC')),
                $userId
            );

            if ($jobId) {
                $this->logger->info('Welcome email scheduled successfully', [
                    'userId' => $userId,
                    'jobId' => $jobId,
                    'email' => $user->getEmail(),
                ]);
            }

            return $jobId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to schedule welcome email', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildValidationUrl(int $userId, string $token): string
    {
        return $this->buildFrontendUrl(sprintf('/validate/%d/%s', $userId, $token));
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

    private function buildPlatformUrl(): string
    {
        return $this->buildFrontendUrl('/');
    }

    private function buildFrontendUrl(string $path): string
    {
        $baseUrl = rtrim($this->frontendBaseUrl, '/');
        $normalizedPath = '/' . ltrim($path, '/');

        if ($normalizedPath === '/') {
            return $baseUrl . '/';
        }

        return $baseUrl . $normalizedPath;
    }
}
