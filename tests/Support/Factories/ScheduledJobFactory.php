<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-described scheduled jobs directly (bypassing the action runtime)
 * for tests that need a job in a specific state — e.g. a queued job that is due
 * now so a test can exercise `app:scheduled-jobs:execute-due`, or a job in a
 * failed state for retry/failure coverage (Slice 2).
 *
 * All jobs are described with a `qa_` prefix and persisted through the real
 * EntityManager; the DAMA transaction rolls them back at tearDown.
 */
final class ScheduledJobFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Create a queued email job due `now` (so it is picked up by both the
     * immediate executor and `app:scheduled-jobs:execute-due`).
     */
    public function createDueQueuedEmailJob(
        User $user,
        string $description = 'qa_scheduled_job',
        ?\DateTimeInterface $dueAt = null,
    ): ScheduledJob {
        return $this->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            $dueAt ?? new \DateTime('now', new \DateTimeZone('UTC')),
            $description,
            ['email' => [
                'recipient_emails' => (string) $user->getEmail(),
                'subject' => 'QA scheduled email',
                'body' => 'QA scheduled email body',
                'from_email' => 'qa-noreply@selfhelp.test',
                'from_name' => 'QA',
                'is_html' => false,
            ]],
        );
    }

    /**
     * Create a queued email job due `now` that is GUARANTEED to fail on
     * execution: the email config carries an explicit empty recipient list, so
     * {@see \App\Service\Core\JobSchedulerService::executeEmailJob()} resolves no
     * recipients and returns false (status -> failed, send_mail_fail logged).
     *
     * This is a deterministic failure (no time/network dependency) used to cover
     * the real failure path. NOTE: the current backend has no Mercure failure
     * event and no auto-retry — tests assert that real behaviour, not aspirational
     * features.
     */
    public function createFailingEmailJob(
        User $user,
        string $description = 'qa_failing_email_job',
        ?\DateTimeInterface $dueAt = null,
    ): ScheduledJob {
        return $this->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            $dueAt ?? new \DateTime('now', new \DateTimeZone('UTC')),
            $description,
            ['email' => [
                // Explicit empty string (not null) so the executor does not fall
                // back to the user's email — this forces a deterministic failure.
                'recipient_emails' => '',
                'subject' => 'QA failing email',
                'body' => 'QA failing email body',
                'from_email' => 'qa-noreply@selfhelp.test',
                'from_name' => 'QA',
                'is_html' => false,
            ]],
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(
        string $jobTypeCode,
        string $statusCode,
        ?User $user,
        \DateTimeInterface $dueAt,
        string $description,
        array $config = [],
    ): ScheduledJob {
        $job = new ScheduledJob();
        $job->setUser($user);
        $job->setJobType($this->lookup(LookupService::JOB_TYPES, $jobTypeCode));
        $job->setStatus($this->lookup(LookupService::SCHEDULED_JOBS_STATUS, $statusCode));
        $job->setDescription($description);
        $job->setDateToBeExecuted($dueAt);
        $job->setConfig($config);

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function lookup(string $typeCode, string $lookupCode): Lookup
    {
        $lookup = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => $typeCode,
            'lookupCode' => $lookupCode,
        ]);

        if (!$lookup instanceof Lookup) {
            throw new \RuntimeException(sprintf(
                'Missing lookup %s/%s. Run: composer test:reset-db',
                $typeCode,
                $lookupCode
            ));
        }

        return $lookup;
    }
}
