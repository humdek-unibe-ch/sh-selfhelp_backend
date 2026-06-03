<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\JSON;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Behavioural coverage for {@see JsonSchemaValidationService::validate()} (plan
 * Phase 8: schema ref behaviour + schema error behaviour).
 *
 * The happy path is exercised everywhere via assertResponseMatchesSchema; here
 * we pin the explicit contract: valid -> [], type mismatch -> error list,
 * cross-file $ref resolution, and missing-schema -> exception (not silent pass).
 */
final class JsonSchemaValidationServiceTest extends QaKernelTestCase
{
    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testValidDataReturnsNoErrors(): void
    {
        $errors = $this->schema->validate((object) ['typeCode' => 'qa', 'lookupCode' => 'x'], 'entities/lookupEntity');

        self::assertSame([], $errors, 'A schema-conformant object must produce no errors.');
    }

    public function testTypeMismatchProducesErrors(): void
    {
        // typeCode is declared as string; an integer must be flagged.
        $errors = $this->schema->validate((object) ['typeCode' => 123], 'entities/lookupEntity');

        self::assertNotEmpty($errors, 'A type mismatch must produce at least one error.');
        self::assertStringContainsString('typeCode', implode("\n", $errors));
    }

    public function testReferencedSchemasResolveForAValidEnvelope(): void
    {
        // responses/admin/common/lookups composes the shared _response_envelope
        // and the lookupEntity item schema via $ref; a valid envelope exercises
        // that cross-file resolution end-to-end.
        $envelope = (object) [
            'status' => 200,
            'message' => 'OK',
            'error' => null,
            'logged_in' => false,
            'meta' => (object) ['version' => 'v1', 'timestamp' => '2026-01-01T00:00:00+00:00'],
            'data' => [
                (object) ['id' => 1, 'typeCode' => 'qa', 'lookupCode' => 'x', 'lookupValue' => 'y', 'lookupDescription' => null],
            ],
        ];

        $errors = $this->schema->validate($envelope, 'responses/admin/common/lookups');

        self::assertSame([], $errors, "Referenced-schema validation must pass:\n" . implode("\n", $errors));
    }

    public function testMissingSchemaThrows(): void
    {
        $this->expectException(FileNotFoundException::class);

        $this->schema->validate((object) [], 'requests/qa/__definitely_missing_schema__');
    }
}
