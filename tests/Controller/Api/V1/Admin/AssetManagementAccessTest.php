<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Who can MANAGE an asset in the CMS is per asset, through role_data_access
 * (resource type `asset`), like data tables: two editors on one instance, each
 * with the asset permissions, only see and change the assets their role was
 * granted. An upload is granted to the uploader's roles that may create assets;
 * the admin role manages everything.
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

        // The other editor neither lists, opens, edits nor deletes it.
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

    /**
     * The CMS draws only what the server would accept, so each asset tells the
     * CALLER what they may do with it: view the file (its groups), edit and
     * delete (the grant bits on that asset). The flags are never part of the
     * cached list, so two callers with different rights never see each
     * other's answer, and every flag matches what the server then does.
     */
    public function testEachAssetTellsTheCallerWhatTheyMayDoWithIt(): void
    {
        $owner = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OWNER_EMAIL)];
        $other = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAs(self::OTHER_EMAIL)];
        $admin = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->loginAsQaAdmin()];

        // A new upload is public and managed in full by the uploader's role.
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_asset');
        file_put_contents($path, 'qa-bytes');
        $this->client->request('POST', '/cms-api/v1/admin/assets', [], ['file' => new UploadedFile($path, 'qa-rights.png', null, null, true)], $owner);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $asset = $this->data();
        self::assertIsInt($asset['id']);
        $id = $asset['id'];
        self::assertSame(['can_view' => true, 'can_update' => true, 'can_delete' => true], $this->rightsOf($asset));

        // Share it with the other role for READING only: it lists the asset,
        // the flags say it may not edit or delete, and the server agrees.
        $this->grantAssetAccess('qa_asset_other_role', $id, DataAccessSecurityService::PERMISSION_READ);
        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa-rights'], [], $other);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['can_view' => true, 'can_update' => false, 'can_delete' => false], $this->rightsOf($this->listedAsset($id)));
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, ['alt' => 'qa not allowed'], [], $other);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $other);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Limit viewing to a group neither caller is in. The owner still
        // manages the asset but cannot open the file, and the delivery URL
        // agrees with the flag.
        $groupId = (int) (new GroupFactory($this->entityManager()))->createGroup('qa_asset_rights_group')->getId();
        $this->client->request('POST', '/cms-api/v1/admin/assets/' . $id, ['group_ids' => (string) $groupId], [], $owner);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $edited = $this->data();
        self::assertSame(['can_view' => false, 'can_update' => true, 'can_delete' => true], $this->rightsOf($edited));
        self::assertIsString($edited['url']);
        $this->client->request('GET', $edited['url'], [], [], $owner);
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'can_view false means the delivery URL refuses the caller');

        // The same asset, asked by each caller in turn: no shared answer.
        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa-rights'], [], $other);
        self::assertSame(['can_view' => false, 'can_update' => false, 'can_delete' => false], $this->rightsOf($this->listedAsset($id)));
        $this->client->request('GET', '/cms-api/v1/admin/assets', ['search' => 'qa-rights'], [], $owner);
        self::assertSame(['can_view' => false, 'can_update' => true, 'can_delete' => true], $this->rightsOf($this->listedAsset($id)));
        $this->client->request('GET', '/cms-api/v1/admin/assets/' . $id, [], [], $admin);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['can_view' => true, 'can_update' => true, 'can_delete' => true], $this->rightsOf($this->data()), 'The admin role views and manages everything');

        // The owner removes the asset (and its stored file).
        $this->client->request('DELETE', '/cms-api/v1/admin/assets/' . $id, [], [], $owner);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<mixed> $asset
     * @return array{can_view: mixed, can_update: mixed, can_delete: mixed}
     */
    private function rightsOf(array $asset): array
    {
        return [
            'can_view' => $asset['can_view'] ?? null,
            'can_update' => $asset['can_update'] ?? null,
            'can_delete' => $asset['can_delete'] ?? null,
        ];
    }

    /**
     * The asset with this id from the list response just received.
     *
     * @return array<mixed>
     */
    private function listedAsset(int $id): array
    {
        $assets = $this->data()['assets'] ?? null;
        self::assertIsArray($assets);
        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['id'] ?? null) === $id) {
                return $asset;
            }
        }
        self::fail('Asset ' . $id . ' is not in the list');
    }

    /**
     * Give a role a grant on one asset. The kernel reboots between requests, so
     * the entity manager, the role and the factory all come from the current
     * container.
     */
    private function grantAssetAccess(string $roleName, int $assetId, int $crud): void
    {
        $em = $this->entityManager();
        $role = $em->getRepository(\App\Entity\Role::class)->findOneBy(['name' => $roleName]);
        self::assertInstanceOf(\App\Entity\Role::class, $role);

        (new RoleDataAccessFactory($em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class)))
            ->grantAssetAccess($role, $assetId, $crud);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
