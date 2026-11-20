<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `GET /admin/pages/{id}` tells the caller what they may do with the page
 * (`rights`: route permission AND grant bit on the page; the superuser all),
 * and publishing checks the update grant on the page like every other write.
 */
#[PhpUnitGroup('security')]
final class PageRightsTest extends QaWebTestCase
{
    private const EDITOR_EMAIL = 'qa.page.rights@selfhelp.test';
    private const EDITOR_ROLE = 'qa_page_rights_role';

    private const EDITOR_PERMISSIONS = [
        'admin.access',
        'admin.page.read',
        'admin.page.create',
        'admin.page.update',
        'admin.page_version.publish',
        'admin.page_version.delete',
    ];

    private EntityManagerInterface $em;
    private Role $editorRole;
    private string $editorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        $this->editorRole = $roles->createRole(self::EDITOR_ROLE);
        foreach (self::EDITOR_PERMISSIONS as $permission) {
            $roles->grantPermission($this->editorRole, $permission);
        }
        $users->createUser(self::EDITOR_EMAIL, 'QA Page Rights', roles: [$this->editorRole]);
        $this->editorToken = $this->loginAs(self::EDITOR_EMAIL);
    }

    public function testEditorSeesUpdateAndPublishOnTheirPageButNotDeleteWithoutThePermission(): void
    {
        // Creating a page gives the creator's role the full grant on it (F14).
        $pageId = $this->createPage('qa_rights_own', $this->editorToken);

        $rights = $this->rightsOf($pageId, $this->editorToken);

        self::assertSame(
            ['can_update' => true, 'can_delete' => false, 'can_publish' => true],
            $rights,
            'Full grant, but no admin.page.delete: delete stays off.',
        );
    }

    public function testReadOnlyGrantGivesNoRightsAndPublishIsRefused(): void
    {
        $adminToken = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_rights_foreign', $adminToken);
        $this->grantOnPage($this->editorRole, $pageId, DataAccessSecurityService::PERMISSION_READ);

        self::assertSame(
            ['can_update' => false, 'can_delete' => false, 'can_publish' => false],
            $this->rightsOf($pageId, $this->editorToken),
            'The route permissions alone are not enough: the page grant is read only.',
        );

        $this->jsonRequest('POST', '/cms-api/v1/admin/pages/' . $pageId . '/versions/publish', [], $this->editorToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'Publishing needs the update grant on the page.',
        );

        self::assertSame(
            ['can_update' => true, 'can_delete' => true, 'can_publish' => true],
            $this->rightsOf($pageId, $adminToken),
            'The superuser may do everything without a stored grant.',
        );
    }

    public function testDeletingAVersionNeedsTheUpdateGrantOnTheVersionsPage(): void
    {
        $adminToken = $this->loginAsQaAdmin();
        $pageId = $this->createPage('qa_rights_versions', $adminToken);
        $olderVersionId = $this->publish($pageId, $adminToken);
        $this->publish($pageId, $adminToken); // the older version is no longer the published one
        $this->grantOnPage($this->editorRole, $pageId, DataAccessSecurityService::PERMISSION_READ);

        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId . '/versions/' . $olderVersionId, null, $this->editorToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'The route permission alone must not delete a version of a page the caller may only read.',
        );

        // The grant is checked on the version's own page, not on the page named in the URL.
        $ownPageId = $this->createPage('qa_rights_versions_own', $this->editorToken);
        $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $ownPageId . '/versions/' . $olderVersionId, null, $this->editorToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'A page the caller may edit in the URL must not unlock a version of another page.',
        );

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $pageId . '/versions/' . $olderVersionId, null, $adminToken),
        );
    }

    public function testImportingSectionsNeedsPageUpdateNotExport(): void
    {
        // An export-only role with the full grant on the page: the route refuses it (F237).
        // Created before any request, while the factories' entities are still managed.
        $roles = new RoleFactory($this->em);
        $exporter = $roles->createRole('qa_page_export_only_role');
        foreach (['admin.access', 'admin.page.read', 'admin.page.export'] as $permission) {
            $roles->grantPermission($exporter, $permission);
        }
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $users->createUser('qa.page.exporter@selfhelp.test', 'QA Page Exporter', roles: [$exporter]);

        $pageId = $this->createPage('qa_rights_import', $this->editorToken);
        $this->grantOnPage($exporter, $pageId, 15);
        $exporterToken = $this->loginAs('qa.page.exporter@selfhelp.test');

        $this->jsonRequest('POST', '/cms-api/v1/admin/pages/' . $pageId . '/sections/import', ['sections' => []], $exporterToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'Importing sections changes the page: admin.page.export alone must not do it.',
        );

        // The editor holds admin.page.update: the route lets the request through.
        $this->jsonRequest('POST', '/cms-api/v1/admin/pages/' . $pageId . '/sections/import', ['sections' => []], $this->editorToken);
        self::assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function publish(int $pageId, string $token): int
    {
        $published = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages/' . $pageId . '/versions/publish', [], $token),
            Response::HTTP_CREATED,
        );
        $versionId = self::asInt($published['version_id'] ?? null);
        self::assertGreaterThan(0, $versionId);

        return $versionId;
    }

    private function createPage(string $keyword, string $token): int
    {
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => '/' . $keyword,
            ], $token),
            Response::HTTP_CREATED,
        );
        $pageId = self::asInt($created['id'] ?? null);
        self::assertGreaterThan(0, $pageId);

        return $pageId;
    }

    /**
     * Reads the page as the given caller, checks the response against its
     * schema, and returns its `rights`.
     *
     * @return array<mixed>
     */
    private function rightsOf(int $pageId, string $token): array
    {
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $token),
        );

        $raw = json_decode((string) $this->client->getResponse()->getContent());
        if (!is_object($raw)) {
            self::fail('The page response is not a JSON object.');
        }
        $errors = $this->service(JsonSchemaValidationService::class)->validate($raw, 'responses/admin/pages/get_page_fields');
        self::assertSame([], $errors, "Page response failed schema validation:\n" . implode("\n", $errors));

        $rights = $data['rights'] ?? null;
        self::assertIsArray($rights);

        return $rights;
    }

    private function grantOnPage(Role $role, int $pageId, int $crud): void
    {
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );
        self::assertNotNull($typeId);
        $type = $this->em->find(Lookup::class, $typeId);
        self::assertInstanceOf(Lookup::class, $type);
        // The requests in between cleared the entity manager: load the role again.
        $managedRole = $this->em->find(Role::class, $role->getId());
        self::assertInstanceOf(Role::class, $managedRole);

        $grant = (new RoleDataAccess())
            ->setRole($managedRole)
            ->setResourceType($type)
            ->setResourceId($pageId)
            ->setCrudPermissions($crud);
        $this->em->persist($grant);
        $this->em->flush();

        $this->service(DataAccessSecurityService::class)->invalidateRolePermissions((int) $role->getId());
    }
}
