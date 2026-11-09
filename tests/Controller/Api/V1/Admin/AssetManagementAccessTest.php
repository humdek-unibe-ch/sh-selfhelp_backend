<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Who can MANAGE an asset in the CMS is per asset, through role_data_access
 * (resource type `asset`), like data tables: two researchers on one instance,
 * each with the asset permissions, only see and change the assets their role
 * was granted. An upload is granted to the uploader's roles; the admin role
 * manages everything.
 */
#[TestGroup('security')]
final class AssetManagementAccessTest extends QaWebTestCase
{
    private const OWNER_EMAIL = 'qa.asset.owner@selfhelp.test';
    private const OTHER_EMAIL = 'qa.asset.other@selfhelp.test';
    private const ASSET_PERMISSIONS = ['admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'];

    private int $otherRoleId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));

        $ownerRole = $roles->createRole('qa_asset_owner_role');
        $otherRole = $roles->createRole('qa_asset_other_role');
        foreach (self::ASSET_PERMISSIONS as $permission) {
            $roles->grantPermission($ownerRole, $permission);
            $roles->grantPermission($otherRole, $permission);
        }
        $users->createUser(self::OWNER_EMAIL, 'QA Asset Owner', roles: [$ownerRole]);
        $users->createUser(self::OTHER_EMAIL, 'QA Asset Other', roles: [$otherRole]);
        $this->otherRoleId = (int) $otherRole->getId();
    }

    public function testAnUploadIsManagedOnlyByTheUploadersRolesUntilShared(): void
    {
        $owner = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OWNER_EMAIL)];
        $other = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OTHER_EMAIL)];

        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, 'qa-bytes');
        $this->client->request('POST', '/cms-api/v1/admin/assets', [], ['file' => new UploadedFile($path, 'qa-owned.png', null, null, true)], $owner);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $asset = $this->data();
        self::assertIsInt($asset['id']);
        $id = $asset['id'];
        self::assertSame([['id' => $this->ownerRoleId(), 'name' => 'qa_asset_owner_role']], $asset['roles']);

        // The other researcher neither lists, opens, edits nor deletes it.
        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa-owned'], [], $other);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->data()['assets']);
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $other);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, ['alt' => 'qa hijack'], [], $other);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $other);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // The admin role manages every asset.
        $admin = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin()];
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $admin);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Shared with the other role, it becomes theirs to manage too.
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, ['role_ids' => $this->ownerRoleId() . ',' . $this->otherRoleId], [], $owner);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $other);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Deleting removes the file and the asset's grants.
        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $other);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $admin);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function ownerRoleId(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $role = $em->getRepository(\App\Entity\Role::class)->findOneBy(['name' => 'qa_asset_owner_role']);
        self::assertInstanceOf(\App\Entity\Role::class, $role);

        return (int) $role->getId();
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);

        return $body['data'];
    }
}
