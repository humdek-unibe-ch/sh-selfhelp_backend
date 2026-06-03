<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for the two {@see \App\Controller\Api\V1\Admin\AdminStyleController}
 * endpoints not exercised by the legacy StyleControllerTest:
 *
 *   - `admin_styles_schema_get` GET /admin/styles/schema (admin.access) — the
 *     style/field schema consumed by import validation + frontend codegen.
 *   - `admin_ai_section_prompt_template_get` GET /admin/ai/section-prompt-template
 *     (admin.page.export) — renders the AI prompt as text/markdown (NOT an
 *     envelope), so its success path is asserted with the raw client.
 */
#[Group('security')]
final class AdminStyleEndpointsTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGetStylesSchemaReturnsTheStyleFieldCatalog(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/styles/schema', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        // The payload is keyed by style name -> { fields, ... }. We assert the
        // contract shape directly rather than the published JSON schema because
        // of a KNOWN, pre-existing response-vs-schema drift: styles with no
        // fields serialise `fields` as an empty array `[]` while
        // responses/style/stylesSchema declares `fields` as an object. Asserting
        // the schema here would lock in a fix that changes the public response
        // shape (`[]` -> `{}`); that belongs to the Phase 10 schema-drift work,
        // not this read-only coverage slice.
        self::assertNotEmpty($data, 'Style schema catalog must not be empty.');
        $first = $data[array_key_first($data)];
        self::assertIsArray($first, 'Each style schema entry must be an object.');
        self::assertArrayHasKey('fields', $first, 'Each style schema entry exposes its fields.');
    }

    public function testStylesSchemaEnforcesTheAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/styles/schema');
    }

    public function testGetSectionPromptTemplateReturnsMarkdown(): void
    {
        $this->client->request(
            'GET',
            '/cms-api/v1/admin/ai/section-prompt-template',
            [],
            [],
            $this->authHeaders($this->loginAsQaAdmin()),
        );
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Prompt template must render for admin.');
        self::assertStringContainsString(
            'text/markdown',
            (string) $response->headers->get('Content-Type'),
            'Prompt template must be served as markdown.',
        );
        self::assertNotEmpty((string) $response->getContent(), 'Rendered prompt template must not be empty.');
    }

    public function testSectionPromptTemplateIsForbiddenForNonAdmins(): void
    {
        // Negative half: denials are JSON envelopes regardless of the markdown
        // success representation, so the matrix helper applies cleanly here.
        $this->assertForbiddenForNonAdmins('GET', '/cms-api/v1/admin/ai/section-prompt-template');
    }
}
