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
 * JSON-schema contract coverage for {@see GET /cms-api/v1/navigation}
 * (canonical Testing Rule 25 — FE/mobile-consumed response).
 */
#[Group('contract')]
final class NavigationSchemaContractTest extends QaWebTestCase
{
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testGuestNavigationResponseMatchesPublishedSchema(): void
    {
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/navigation'),
            Response::HTTP_OK,
        );
        $this->assertLastResponseMatchesSchema('responses/frontend/get_navigation');
    }

    public function testAuthenticatedNavigationResponseMatchesPublishedSchema(): void
    {
        $user = $this->loginAsQaUser();
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/navigation', null, $user),
            Response::HTTP_OK,
        );
        $this->assertLastResponseMatchesSchema('responses/frontend/get_navigation');
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        // get_navigation.json describes the `data` payload only (not the envelope).
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), false);
        self::assertIsObject($decoded, 'Response body must be a JSON object.');
        self::assertIsObject($decoded->data ?? null, 'Response data must be a JSON object.');
        $errors = $this->schema->validate($decoded->data, $schemaName);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schemaName, implode("\n", $errors)));
    }
}
