<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\CMS\DataService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

#[Group('contract')]
final class DataQueryPreviewSchemaContractTest extends QaWebTestCase
{
    private DataTableFactory $dataTables;
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $dataService = self::getContainer()->get(DataService::class);
        self::assertInstanceOf(DataService::class, $dataService);
        $this->dataTables = new DataTableFactory($em, $dataService);
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testQueryPreviewResponseMatchesPublishedSchema(): void
    {
        $admin = $this->loginAsQaAdmin();
        $table = $this->dataTables->createTable('qa_query_preview_contract');

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/data/query-preview', [
                'data_table' => (int) $table->getId(),
                'filter' => 'AND record_id = {{route.record_id}}',
                'route_params' => ['record_id' => '7'],
            ], $admin),
        );
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $decoded = self::asObject($decoded);
        $payload = $decoded->data;
        self::assertTrue(is_array($payload) || is_object($payload));
        $errors = $this->schema->validate($payload, 'responses/admin/data_query_preview');
        self::assertSame([], $errors, sprintf("Response failed schema responses/admin/data_query_preview:\n%s", implode("\n", $errors)));
    }

    public function testUnsafeFilterPreviewReturnsErrors(): void
    {
        $admin = $this->loginAsQaAdmin();

        $preview = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/data/query-preview', [
                'filter' => 'AND 1=1; DROP TABLE data_rows',
            ], $admin),
        );
        self::assertNotEmpty($preview['errors'] ?? []);
        self::assertSame('', $preview['prepared_filter'] ?? 'not-empty');
    }
}
