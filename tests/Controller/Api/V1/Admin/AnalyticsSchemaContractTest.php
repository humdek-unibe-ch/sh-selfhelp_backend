<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON-schema contract coverage for admin analytics dashboard endpoints
 * (canonical Testing Rule 25 — FE-consumed responses).
 */
#[Group('contract')]
final class AnalyticsSchemaContractTest extends QaWebTestCase
{
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testAnalyticsSummaryResponseMatchesPublishedSchema(): void
    {
        $admin = $this->loginAsQaAdmin();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'GET',
                '/cms-api/v1/admin/analytics/summary?from=' . $today . '&to=' . $today . '&granularity=day&platform=all',
                null,
                $admin,
            ),
            Response::HTTP_OK,
        );
        $this->assertLastResponseMatchesSchema('responses/admin/analytics/analytics_summary');
    }

    public function testAnalyticsTodayResponseMatchesPublishedSchema(): void
    {
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/analytics/today', null, $admin),
            Response::HTTP_OK,
        );
        $this->assertLastResponseMatchesSchema('responses/admin/analytics/analytics_today');
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');
        $errors = $this->schema->validate($decoded, $schemaName);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schemaName, implode("\n", $errors)));
    }
}
