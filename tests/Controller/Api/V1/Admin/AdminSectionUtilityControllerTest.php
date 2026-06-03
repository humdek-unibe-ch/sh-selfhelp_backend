<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Section;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Admin\AdminSectionUtilityController}.
 *
 * Read routes (`/admin/sections/unused`, `/admin/sections/ref-containers`,
 * permission admin.page.update) certify the envelope + schema. The single-delete
 * route (permission admin.section.delete) is exercised against a self-seeded
 * unused `qa_` section and asserts the row is gone (public side effect). The
 * destructive delete-all route only asserts the negative permission half so the
 * test never mass-deletes real unused sections (Testing Rule 9).
 */
#[Group('security')]
final class AdminSectionUtilityControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private EntityManagerInterface $em;
    private JsonSchemaValidationService $schema;
    private PageSectionFactory $pages;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $schema = $container->get(JsonSchemaValidationService::class);
        self::assertInstanceOf(JsonSchemaValidationService::class, $schema);
        $this->schema = $schema;

        $acl = $container->get(ACLService::class);
        self::assertInstanceOf(ACLService::class, $acl);
        $lookup = $container->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookup);
        $cache = $container->get(CacheService::class);
        self::assertInstanceOf(CacheService::class, $cache);

        $this->pages = new PageSectionFactory($this->em, $acl, $lookup, $cache);
        $this->adminToken = $this->loginAsQaAdmin();
    }

    public function testGetUnusedSectionsReturnsListEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/sections/unused', null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        $this->assertLastResponseMatchesSchema('responses/admin/sections/unused_sections_envelope');
    }

    public function testGetRefContainersReturnsListEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/sections/ref-containers', null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        $this->assertLastResponseMatchesSchema('responses/admin/sections/ref_containers_envelope');
    }

    public function testDeleteUnusedSectionRemovesTheSection(): void
    {
        // A freshly-created, unlinked section is "unused" by definition.
        $section = $this->pages->createSection('qa_unused_section_util');
        $id = (int) $section->getId();

        $this->client->request(
            'DELETE',
            "/cms-api/v1/admin/sections/unused/{$id}",
            [],
            [],
            $this->authHeaders($this->adminToken),
        );

        // Success path returns 204 No Content (Symfony strips the body).
        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $this->client->getResponse()->getStatusCode(),
            'Deleting an unused section must return 204.',
        );

        // Public side effect: the section row is gone.
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Section::class)->find($id),
            'Deleted unused section must no longer exist.',
        );
    }

    public function testDeleteUnusedSectionForUnknownIdReturns404(): void
    {
        $envelope = $this->jsonRequest(
            'DELETE',
            '/cms-api/v1/admin/sections/unused/2147483600',
            null,
            $this->adminToken,
        );

        $this->assertEnvelope404($envelope);
    }

    public function testUnusedSectionsListIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('GET', '/cms-api/v1/admin/sections/unused');
    }

    public function testDeleteUnusedSectionIsForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/sections/unused/2147483600');
    }

    public function testDeleteAllUnusedSectionsIsForbiddenForNonAdmins(): void
    {
        // Negative half only: the admin success path would mass-delete every real
        // unused section, so it is intentionally not exercised here (Rule 9).
        $this->assertForbiddenForNonAdmins('DELETE', '/cms-api/v1/admin/sections/unused');
    }

    private function assertLastResponseMatchesSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }
}
