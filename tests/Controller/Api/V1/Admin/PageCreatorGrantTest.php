<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\Core\LookupService;
use App\Service\Security\CreatorGrantService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F14: roles that hold `admin.page.create` receive full page data-access when
 * they create a page (directly, via bundle import, or via CMS-app scaffold).
 * The admin role is never stored; companion roles without create are not granted.
 */
#[PhpUnitGroup('security')]
final class PageCreatorGrantTest extends QaWebTestCase
{
    private const CREATOR_EMAIL = 'qa.page.creator@selfhelp.test';
    private const CREATOR_ROLE = 'qa_page_creator_role';
    private const READER_ROLE = 'qa_page_reader_role';

    private const PAGE_PERMISSIONS = [
        'admin.access',
        'admin.page.read',
        'admin.page.create',
        'admin.page.update',
    ];

    private const CMS_APP_PERMISSIONS = [
        'admin.cms_app.read',
        'admin.cms_app.create',
        'admin.cms_app.update',
    ];

    private EntityManagerInterface $em;
    private RoleFactory $roles;
    private int $creatorRoleId;
    private int $readerRoleId;
    private string $creatorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->entityManager();
        $this->roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        $creator = $this->roles->createRole(self::CREATOR_ROLE);
        $reader = $this->roles->createRole(self::READER_ROLE);
        foreach (self::PAGE_PERMISSIONS as $permission) {
            $this->roles->grantPermission($creator, $permission);
        }
        $this->roles->grantPermission($reader, 'admin.access');
        $this->roles->grantPermission($reader, 'admin.page.read');

        foreach (self::CMS_APP_PERMISSIONS as $permission) {
            $this->roles->grantPermission($creator, $permission);
        }

        $users->createUser(self::CREATOR_EMAIL, 'QA Page Creator', roles: [$creator, $reader]);
        $this->creatorRoleId = (int) $creator->getId();
        $this->readerRoleId = (int) $reader->getId();
        $this->creatorToken = $this->loginAs(self::CREATOR_EMAIL);
    }

    public function testCreatorRoleGetsFullGrantAndCanGetAndUpdatePage(): void
    {
        $keyword = 'qa_f14_created';
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => $keyword,
                'pageAccessTypeCode' => 'web',
                'url' => '/' . $keyword,
            ], $this->creatorToken),
            Response::HTTP_CREATED,
        );
        $pageId = self::asInt($created['id'] ?? null);

        self::assertSame(
            CreatorGrantService::FULL_ACCESS,
            $this->pageGrantCrud($this->creatorRoleId, $pageId),
            'Creator role must hold full CRUD on the new page.',
        );
        self::assertNull(
            $this->pageGrant($this->readerRoleId, $pageId),
            'A second role without admin.page.create must get no page grant.',
        );
        self::assertSame(
            0,
            $this->pageGrantRowCount($this->adminRoleId(), $pageId),
            'The admin role must not be stored on the new page.',
        );

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $this->creatorToken),
        );
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('PUT', '/cms-api/v1/admin/pages/' . $pageId, [
                'pageData' => ['openAccess' => true],
                'fields' => [],
            ], $this->creatorToken),
        );
    }

    public function testBundleImportedPageGetsCreatorGrant(): void
    {
        $keyword = 'qa_f14_imported';
        $bundle = $this->minimalPageBundle($keyword);
        $data = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
                'bundle' => $bundle,
                'options' => ['keywordPrefix' => '', 'routePrefix' => ''],
            ], $this->creatorToken),
            Response::HTTP_CREATED,
        );
        $pageId = $this->firstImportedPageId($data);
        self::assertGreaterThan(0, $pageId);

        self::assertSame(CreatorGrantService::FULL_ACCESS, $this->pageGrantCrud($this->creatorRoleId, $pageId));
        self::assertNull($this->pageGrant($this->readerRoleId, $pageId));
        self::assertSame(0, $this->pageGrantRowCount($this->adminRoleId(), $pageId));

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $this->creatorToken),
        );
    }

    public function testWizardScaffoldedPageGetsCreatorGrant(): void
    {
        $slug = 'qa-f14-wizard-app';
        $app = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => $slug,
                'slug' => $slug,
            ], $this->creatorToken),
            Response::HTTP_CREATED,
        );
        $appId = self::asInt($app['id'] ?? null);

        $scaffold = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps/' . $appId . '/scaffold', [
                'base_name' => 'qa-f14-wizard',
                'create_form' => true,
                'create_public' => false,
                'create_admin' => true,
                'with_status' => false,
            ], $this->creatorToken),
            Response::HTTP_CREATED,
        );

        $pageIds = [];
        foreach (self::asList($scaffold['created'] ?? null) as $entry) {
            $row = self::asArray($entry);
            if (isset($row['page_id'])) {
                $pageIds[] = self::asInt($row['page_id']);
            }
        }
        self::assertNotEmpty($pageIds, 'Scaffold must create at least one page.');

        foreach ($pageIds as $pageId) {
            self::assertSame(
                CreatorGrantService::FULL_ACCESS,
                $this->pageGrantCrud($this->creatorRoleId, $pageId),
                'Every scaffolded page must grant the creator role.',
            );
            self::assertSame(0, $this->pageGrantRowCount($this->adminRoleId(), $pageId));
            $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/pages/' . $pageId, null, $this->creatorToken),
            );
        }
    }

    // -- helpers --------------------------------------------------------------

    /**
     * @param array<string, mixed> $importResult
     */
    private function firstImportedPageId(array $importResult): int
    {
        foreach (self::asList($importResult['created'] ?? null) as $entry) {
            $row = self::asArray($entry);
            if (isset($row['page_id'])) {
                return self::asInt($row['page_id']);
            }
        }

        self::fail('Import response must list created page ids.');
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPageBundle(string $keyword): array
    {
        $raw = file_get_contents(ExampleBundleTestPaths::heroHomeBundle());
        self::assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $bundle = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $bundle[$key] = $value;
            }
        }
        $bundle['pages'] = [[
            'keyword' => $keyword,
            'surface' => 'public',
            'page_access_type' => 'web',
            'headless' => false,
            'open_access' => true,
            'url' => '/' . $keyword,
            'sections' => [[
                'style_name' => 'title',
                'fields' => ['content' => ['en-GB' => ['content' => 'Imported']]],
            ]],
        ]];

        return $bundle;
    }

    private function pageGrantCrud(int $roleId, int $pageId): ?int
    {
        $grant = $this->pageGrant($roleId, $pageId);

        return $grant?->getCrudPermissions();
    }

    private function pageGrant(int $roleId, int $pageId): ?RoleDataAccess
    {
        $this->em->clear();
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );
        self::assertNotNull($typeId);

        $grant = $this->em->getRepository(RoleDataAccess::class)->findOneBy([
            'idRoles' => $roleId,
            'idResourceTypes' => $typeId,
            'resourceId' => $pageId,
        ]);

        return $grant instanceof RoleDataAccess ? $grant : null;
    }

    private function pageGrantRowCount(int $roleId, int $pageId): int
    {
        $this->em->clear();
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES,
        );
        self::assertNotNull($typeId);

        return $this->em->getRepository(RoleDataAccess::class)->count([
            'idRoles' => $roleId,
            'idResourceTypes' => $typeId,
            'resourceId' => $pageId,
        ]);
    }

    private function adminRoleId(): int
    {
        $admin = $this->em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $admin);

        return (int) $admin->getId();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}