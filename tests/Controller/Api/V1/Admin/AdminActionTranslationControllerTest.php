<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Admin\AdminActionTranslationController}.
 *
 * Route `admin_actions_translations_get_all_v1`
 * (GET /admin/actions/{actionId}/translations, permission
 * admin.action_translation.read). The success path runs against a self-seeded
 * `qa_` action (DAMA rolls it back), the not-found branch against a sentinel id,
 * and the permission matrix proves admin-only access.
 */
#[Group('security')]
final class AdminActionTranslationControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private EntityManagerInterface $em;
    private JsonSchemaValidationService $schema;
    private int $actionId;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->schema = $this->service(JsonSchemaValidationService::class);
        $this->adminToken = $this->loginAsQaAdmin();

        $action = (new ActionFactory($this->em))->createImmediateEmailAction('qa_action_translation_table');
        $this->actionId = (int) $action->getId();
    }

    public function testGetTranslationsReturnsAListEnvelopeForKnownAction(): void
    {
        $envelope = $this->jsonRequest(
            'GET',
            "/cms-api/v1/admin/actions/{$this->actionId}/translations",
            null,
            $this->adminToken,
        );
        $this->assertEnvelopeSuccess($envelope);

        // A freshly-created action has no translations yet -> empty but valid
        // list, fully asserted by the response schema below.
        $this->assertLastResponseMatchesSchema('responses/admin/actions/translations_list_envelope');
    }

    public function testGetTranslationsForUnknownActionReturns404(): void
    {
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/actions/2147483600/translations',
            null,
            $this->adminToken,
        );

        $this->assertEnvelope404($envelope);
    }

    public function testTranslationsEndpointEnforcesTheAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', "/cms-api/v1/admin/actions/{$this->actionId}/translations");
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }
}
