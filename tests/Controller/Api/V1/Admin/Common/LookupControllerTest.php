<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Common;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Admin\Common\LookupController}.
 *
 * Two routes share the class:
 *   - `system_lookups`  GET /lookups            — reference data (timezones, type
 *     codes, …). It matches the "^/cms-api/v1 => PUBLIC_ACCESS" access-control
 *     rule and is NOT admin-gated since migration Version20260508160000, so an
 *     anonymous caller still receives it (public frontend styles consume it).
 *   - `admin_page_keywords` GET /admin/page-keywords — admin.access gated, so it
 *     follows the canonical admin-only matrix.
 */
#[Group('security')]
final class LookupControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testSystemLookupsArePubliclyReadableAndMatchSchema(): void
    {
        // No token: the route is public reference data.
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/lookups', null, null);
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertNotEmpty($data, 'System lookups must not be empty (run: composer test:reset-db).');
        $rows = $this->asList($data);
        $first = $this->asArray($rows[0] ?? null, 'Lookups payload must be a numerically-indexed list.');
        self::assertArrayHasKey('typeCode', $first, 'Each lookup row exposes a typeCode.');

        $this->assertLastResponseMatchesSchema('responses/admin/common/lookups');
    }

    public function testSystemLookupsAreAlsoReadableWithAnAdminToken(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/lookups', null, $this->loginAsQaAdmin());
        $this->assertEnvelopeSuccess($envelope);
    }

    public function testPageKeywordsEnforceTheAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/page-keywords');
    }

    public function testPageKeywordsReturnAListForAdmin(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/page-keywords', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertNotEmpty($this->asList($data), 'Page-keywords payload must be a non-empty list.');
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }
}
