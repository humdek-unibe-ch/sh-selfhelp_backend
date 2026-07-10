<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON-schema contract coverage for {@see GET /cms-api/v1/pages/resolve}
 * (canonical Testing Rule 25 — FE/mobile-consumed response).
 *
 * Resolve reuses the published `responses/frontend/get_page` schema (same
 * envelope as keyword/path page content, plus optional route metadata fields).
 * Uses the seeded open-access `/home` route so the contract does not depend on
 * admin page-create (which can close the EntityManager on unrelated failures).
 */
#[Group('contract')]
final class PageResolveSchemaContractTest extends QaWebTestCase
{
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testResolveResponseMatchesPublishedGetPageSchema(): void
    {
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/pages/resolve?path=' . rawurlencode('/home')),
            Response::HTTP_OK,
        );
        $this->assertLastResponseMatchesSchema('responses/frontend/get_page');
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');
        $errors = $this->schema->validate($decoded, $schemaName);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schemaName, implode("\n", $errors)));
    }
}
