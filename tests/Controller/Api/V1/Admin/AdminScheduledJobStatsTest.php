<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Contract + behaviour for GET /admin/scheduled-jobs/stats.
 *
 * The endpoint is unfiltered by design: it counts the whole set of scheduled
 * jobs (ignores date/search/status/type). queued/done/failed/deleted map to the
 * scheduledJobsStatus lookup codes; total counts all jobs. The four status
 * counts are independent, not a partition — deleted jobs still count toward
 * total, so they need not sum to it. The behaviour test seeds one job per status
 * through the real factory and asserts each count (and total) moves by exactly
 * the seeded delta, then relies on DAMA rollback for cleanup.
 */
#[Group('contract')]
final class AdminScheduledJobStatsTest extends QaWebTestCase
{
    private const URI = '/cms-api/v1/admin/scheduled-jobs/stats';

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testStatsResponseMatchesSchema(): void
    {
        $admin = $this->loginAsQaAdmin();

        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::URI, null, $admin),
        );

        $decoded = self::asObject(
            json_decode((string) $this->client->getResponse()->getContent(), false, 512, JSON_THROW_ON_ERROR),
        );
        $errors = $this->schema->validate($decoded, 'responses/admin/scheduled_jobs/stats');
        self::assertSame([], $errors, "Response failed schema responses/admin/scheduled_jobs/stats:\n" . implode("\n", $errors));

        // All five keys present and integers.
        foreach (['total', 'queued', 'done', 'failed', 'deleted'] as $key) {
            self::assertIsInt($data[$key] ?? null, "stats.$key must be an integer.");
        }
    }

    public function testSeedingJobsAcrossStatusesMovesEachCount(): void
    {
        // One kernel for the whole chain so the DAMA transaction stays consistent
        // across the seed writes and the two stats reads.
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->loginAsQaAdmin();

        $before = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $admin));

        // Seed one job in each of the four reported statuses (2 queued so the
        // delta differs from the others and can't accidentally match). The stats
        // aggregate groups by status only and never joins the user, so these jobs
        // carry no owner — that keeps the seed independent of EM identity-map
        // state across the login request.
        $factory = new ScheduledJobFactory($em);
        $due     = new \DateTime('now', new \DateTimeZone('UTC'));
        $seed    = [
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED  => 2,
            LookupService::SCHEDULED_JOBS_STATUS_DONE    => 1,
            LookupService::SCHEDULED_JOBS_STATUS_FAILED  => 1,
            LookupService::SCHEDULED_JOBS_STATUS_DELETED => 1,
        ];
        foreach ($seed as $statusCode => $count) {
            for ($i = 0; $i < $count; $i++) {
                $factory->create(
                    LookupService::JOB_TYPES_EMAIL,
                    $statusCode,
                    null,
                    $due,
                    'qa_stats_' . $statusCode . '_' . $i,
                    [],
                );
            }
        }

        $after = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $admin));

        self::assertSame($this->intField($before, 'queued') + 2, $this->intField($after, 'queued'), 'queued count moves by 2.');
        self::assertSame($this->intField($before, 'done') + 1, $this->intField($after, 'done'), 'done count moves by 1.');
        self::assertSame($this->intField($before, 'failed') + 1, $this->intField($after, 'failed'), 'failed count moves by 1.');
        self::assertSame($this->intField($before, 'deleted') + 1, $this->intField($after, 'deleted'), 'deleted count moves by 1.');
        // Deleted jobs still count toward total: all 5 seeded jobs raise total.
        self::assertSame($this->intField($before, 'total') + 5, $this->intField($after, 'total'), 'total counts every seeded job, including deleted.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        self::assertIsInt($value, "stats.$key must be an integer.");

        return $value;
    }
}
