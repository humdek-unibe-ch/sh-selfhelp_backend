<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\Plugin\QaDeliveryAwarePluginJobHandler;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #36: communication preferences are enforced for plugin-contributed
 * scheduled jobs, not just core email/notification jobs.
 *
 * A delivery-aware plugin handler ({@see QaDeliveryAwarePluginJobHandler}) is
 * registered in the real {@see \App\Plugin\ScheduledJob\PluginScheduledJobRegistry}
 * via `config/services_test.yaml`. The host gate runs BEFORE the handler, so a
 * recipient who disabled emails turns the job into the same audited `skipped_*`
 * outcome a core email job produces — and the plugin handler is never invoked,
 * proving a plugin cannot silently bypass {@see User::receivesEmails()}.
 */
#[Group('security')]
final class PluginScheduledJobPreferenceTest extends QaKernelTestCase
{
    private JobSchedulerService $scheduler;
    private ScheduledJobFactory $jobs;
    private QaDeliveryAwarePluginJobHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = $this->service(JobSchedulerService::class);
        $this->jobs = new ScheduledJobFactory($this->em);
        $this->handler = $this->service(QaDeliveryAwarePluginJobHandler::class);
        $this->ensurePluginJobTypeLookup();
    }

    public function testPluginEmailJobIsSkippedWhenRecipientDisabledEmails(): void
    {
        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $this->em->flush();

        $job = $this->jobs->create(
            QaDeliveryAwarePluginJobHandler::JOB_TYPE,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_plugin_pref_skip',
        );

        $result = $this->scheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result, 'A skipped job is terminal and must return its entity.');
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $job->getStatus()->getLookupCode(),
            'A plugin email job for a user who disabled emails must end SKIPPED, mirroring core jobs.'
        );
        self::assertNotNull($job->getDateExecuted(), 'A skipped job still records a terminal execution timestamp.');
        self::assertSame(
            0,
            $this->handler->executions,
            'The plugin handler must NOT be invoked when the host gate skips the disabled channel.'
        );
    }

    public function testPluginEmailJobRunsWhenRecipientAllowsEmails(): void
    {
        $user = $this->qaUser();
        $user->setReceivesEmails(true);
        $this->em->flush();

        $job = $this->jobs->create(
            QaDeliveryAwarePluginJobHandler::JOB_TYPE,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_plugin_pref_run',
        );

        $result = $this->scheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'A plugin job runs to DONE when the recipient accepts the declared channel.'
        );
        self::assertSame(
            1,
            $this->handler->executions,
            'The plugin handler runs exactly once when delivery is allowed.'
        );
    }

    /**
     * The plugin handler claims a `qa_`-scoped job type that is not part of the
     * core lookup seed. Create it on demand so a job can reference it; the DAMA
     * transaction rolls it back at tearDown.
     */
    private function ensurePluginJobTypeLookup(): void
    {
        $existing = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::JOB_TYPES,
            'lookupCode' => QaDeliveryAwarePluginJobHandler::JOB_TYPE,
        ]);
        if ($existing instanceof Lookup) {
            return;
        }

        $lookup = (new Lookup())
            ->setTypeCode(LookupService::JOB_TYPES)
            ->setLookupCode(QaDeliveryAwarePluginJobHandler::JOB_TYPE)
            ->setLookupValue('QA Plugin Delivery');
        $this->em->persist($lookup);
        $this->em->flush();
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
