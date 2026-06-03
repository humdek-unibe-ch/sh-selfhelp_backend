<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Smoke;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-deploy readiness smoke (plan §18.3). One test that touches every
 * core subsystem a freshly deployed instance must have working, in well
 * under the 60s post-deploy budget:
 *
 *   0. the QA baseline is the expected version (printed for triage);
 *   1. the PUBLIC /cms-api/v1/health probe answers 200 + {status, database};
 *   2. qa.admin performs a REAL JWT login;
 *   3. a throwaway qa page round-trips through the admin CMS write path
 *      (create -> delete) — proving DB writes + transactions + cache
 *      invalidation are wired;
 *   4. a due queued scheduled job executes to `done` via the real cron
 *      command (`app:scheduled-jobs:execute-due`);
 *   5. a realtime publish fires: bumping a qa user's ACL version emits one
 *      `acl-changed` Mercure update (captured by the in-memory recorder —
 *      no real outbound, plan §9/§30).
 *
 * Everything is qa-scoped and rolled back by the DAMA transaction. This is
 * the suite the post-deploy workflow runs against a freshly migrated +
 * seeded database to decide a deploy is healthy.
 *
 * @group smoke
 */
#[Group('smoke')]
final class HealthSmokeTest extends QaWebTestCase
{
    /**
     * The post-deploy budget (plan §18.3). Generous on purpose: the smoke
     * is in-process and finishes in a fraction of this, so the assertion
     * only trips on a genuine, gross regression — never on CI jitter.
     */
    private const POST_DEPLOY_BUDGET_MS = 60000;

    public function testPostDeployReadinessChainAcrossCoreSubsystems(): void
    {
        $startedAt = microtime(true);

        // One kernel for the whole chain. The smoke writes in one request
        // (page create) and reads it back in the next (page delete); keeping
        // a single kernel keeps the DAMA transaction + EM identity map
        // consistent across those requests (matches the certification base).
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 0) Fixture version: print for failure triage, assert for safety.
        $fixtureVersion = $this->readQaFixtureVersion($em);
        fwrite(STDOUT, sprintf("\nQA_FIXTURE_VERSION=%s\n", (string) $fixtureVersion));
        self::assertSame(
            QaBaselineFixture::QA_FIXTURE_VERSION,
            $fixtureVersion,
            'Database was seeded with a different QA fixture version. Run: composer test:reset-db'
        );

        // 1) Public health probe — no auth, minimal payload, DB confirmed.
        $health = $this->jsonRequest('GET', '/cms-api/v1/health');
        $healthData = $this->assertEnvelopeSuccess($health);
        self::assertSame('ok', $healthData['status'] ?? null, 'Health probe must report status=ok.');
        self::assertSame('ok', $healthData['database'] ?? null, 'Health probe must confirm the database is reachable.');

        // 2) Auth: qa.admin obtains a real JWT.
        $token = $this->loginAsQaAdmin();
        self::assertNotSame('', $token, 'qa.admin must receive a non-empty access token.');

        // 3) CMS write path: a throwaway qa page round-trips create -> delete.
        $keyword = 'qa_smoke_health_page';
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => $keyword,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . $keyword,
            'navPosition' => 100,
            'footerPosition' => null,
            'parent' => null,
        ], $token);
        $createdData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        self::assertSame($keyword, $createdData['keyword'] ?? null, 'Created page must echo its keyword.');
        self::assertIsInt($createdData['id'] ?? null, 'Created page must return its numeric id.');
        $pageId = (int) $createdData['id'];

        // Pages are deleted by numeric id (DELETE /admin/pages/{page_id});
        // deletePage() returns the removed page so we can confirm identity.
        $deleted = $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId, null, $token);
        $deletedData = $this->assertEnvelopeSuccess($deleted);
        self::assertSame($keyword, $deletedData['keyword'] ?? null, 'Deleted page must echo its keyword.');

        $qaUser = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $qaUser, 'qa.user must be seeded. Run: composer test:reset-db');

        // 4) Realtime: bumping a qa user's ACL version publishes exactly one
        // acl-changed Mercure update through the real persistence-boundary
        // listener (no real outbound — the in-memory recorder captures it).
        // Done before the cron step because that step clears the EM.
        /** @var MercureTestRecorder $mercure */
        $mercure = self::getContainer()->get(MercureTestRecorder::class);
        $mercure->reset();
        $qaUser->bumpAclVersion();
        $em->flush();
        $mercure->assertTopicPublished(
            '/acl',
            'Bumping a QA user ACL version must publish an acl-changed Mercure update.'
        );

        // 5) Scheduled job execution: a due queued job runs to `done` via the
        // real cron command.
        $job = (new ScheduledJobFactory($em))->createDueQueuedEmailJob($qaUser, 'qa_smoke_due_job');
        $jobId = (int) $job->getId();

        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('app:scheduled-jobs:execute-due'));
        self::assertSame(0, $tester->execute(['--limit' => '999']), 'execute-due must exit 0: ' . $tester->getDisplay());

        // The cron command batches and may clear the EM, so re-read fresh
        // managed state from the DB rather than refreshing a detached entity.
        $em->clear();
        $executedJob = $em->getRepository(ScheduledJob::class)->find($jobId);
        self::assertInstanceOf(ScheduledJob::class, $executedJob, 'The qa job must still exist after execution.');
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $executedJob->getStatus()->getLookupCode(),
            'The due queued job must execute to status=done.'
        );

        // Post-deploy budget (plan §18.3).
        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        self::assertLessThan(
            self::POST_DEPLOY_BUDGET_MS,
            $elapsedMs,
            sprintf('Post-deploy smoke chain took %.0f ms, exceeding the %d ms budget.', $elapsedMs, self::POST_DEPLOY_BUDGET_MS)
        );
    }
}
