<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Group;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end behaviour of the group-scoped asset-folder ACLs, driven through the
 * real routes as qa.admin.
 *
 * The admin ROLE always has full access, mirroring the page-ACL contract
 * ({@see \App\Service\ACL\ACLService::hasAccess}): granting a folder to some
 * OTHER group must NOT lock an admin-role user out of it. Non-admin-role
 * enforcement (the grant/union path) is covered at the service level in
 * {@see \App\Tests\Service\CMS\Admin\AssetFolderAclServiceTest}.
 *
 * All writes go through the `subject` group with a `qa_`-prefixed folder and are
 * cleared in teardown.
 */
#[TestGroup('security')]
final class AdminGroupAssetAclBehaviorTest extends QaWebTestCase
{
    private const FOLDER = 'qa_group_acl_folder';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminToken = $this->loginAsQaAdmin();
    }

    protected function tearDown(): void
    {
        if (isset($this->adminToken)) {
            $this->jsonRequest(
                'PUT',
                '/cms-api/v1/admin/groups/' . $this->groupId('subject') . '/asset-acls',
                ['acls' => []],
                $this->adminToken
            );
        }

        parent::tearDown();
    }

    public function testGroupAssetAclsEndpointReturnsTheGroupsGrantsAndOmitsUngrantedFolders(): void
    {
        // Note: uploading an asset seeds default folder ACLs (subject/therapist
        // read), so a group is not globally empty. This asserts the endpoint
        // shape and that a folder the group was never granted is absent.
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/groups/' . $this->groupId('subject') . '/asset-acls',
            null,
            $this->adminToken
        );

        self::assertSame(Response::HTTP_OK, $envelope['status'] ?? null);
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame($this->groupId('subject'), $data['id_groups'] ?? null);

        $acls = $data['acls'] ?? null;
        self::assertIsArray($acls);
        $folders = array_map(static fn ($a) => is_array($a) ? ($a['folder'] ?? null) : null, $acls);
        self::assertNotContains(self::FOLDER, $folders, 'A folder the group was never granted must not appear');
    }

    public function testUpdatePersistsTheGroupsFolderGrants(): void
    {
        $update = $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/groups/' . $this->groupId('subject') . '/asset-acls',
            ['acls' => [['folder' => self::FOLDER, 'access_level' => 'read']]],
            $this->adminToken
        );
        self::assertSame(Response::HTTP_OK, $update['status'] ?? null, 'Admin must be able to set the group ACL');

        $data = $update['data'] ?? null;
        self::assertIsArray($data);
        $acls = $data['acls'] ?? null;
        self::assertIsArray($acls);
        self::assertCount(1, $acls, 'The group should now hold exactly one folder grant');
        $first = $acls[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(self::FOLDER, $first['folder'] ?? null);
        self::assertSame('read', $first['access_level'] ?? null);
    }

    public function testAdminRoleIsNeverLockedOutOfARestrictedFolder(): void
    {
        // Grant the folder to `subject` ONLY (qa.admin is not in subject).
        $this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/groups/' . $this->groupId('subject') . '/asset-acls',
            ['acls' => [['folder' => self::FOLDER, 'access_level' => 'read']]],
            $this->adminToken
        );

        // qa.admin holds the admin ROLE, so ACLs are bypassed: listing the
        // restricted folder still succeeds (mirrors the page-ACL contract).
        $list = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/assets?folder=' . self::FOLDER,
            null,
            $this->adminToken
        );
        self::assertSame(
            Response::HTTP_OK,
            $list['status'] ?? null,
            'An admin-role user must never be locked out of a folder, even one granted to another group'
        );

        // And an admin-role create into that folder is allowed too.
        $this->client->request(
            'POST',
            '/cms-api/v1/admin/assets',
            ['folder' => self::FOLDER, 'overwrite' => '1'],
            ['file' => $this->makeUploadedTextFile('qa_admin_bypass.txt')],
            ['HTTP_Authorization' => 'Bearer ' . $this->adminToken]
        );
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_CREATED, Response::HTTP_OK],
            'An admin-role user must be able to create in a folder granted only to another group'
        );

        // Clean up the asset we just created.
        $this->deleteQaAsset(self::FOLDER, 'qa_admin_bypass.txt');
    }

    private function deleteQaAsset(string $folder, string $fileName): void
    {
        $listing = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/assets?folder=' . $folder . '&search=' . $fileName,
            null,
            $this->adminToken
        );
        $data = $listing['data'] ?? null;
        $assets = is_array($data) ? ($data['assets'] ?? null) : null;
        if (!is_array($assets)) {
            return;
        }
        foreach ($assets as $asset) {
            if (is_array($asset) && ($asset['file_name'] ?? null) === $fileName && is_numeric($asset['id'] ?? null)) {
                $this->jsonRequest('DELETE', '/cms-api/v1/admin/assets/' . (int) $asset['id'], null, $this->adminToken);
            }
        }
    }

    private function groupId(string $name): int
    {
        $em = $this->service(EntityManagerInterface::class);
        $group = $em->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group, sprintf('Seeded "%s" group is required for this test', $name));

        return (int) $group->getId();
    }

    private function makeUploadedTextFile(string $name): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '_' . $name;
        file_put_contents($path, 'qa test content');

        return new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, 'text/plain', null, true);
    }
}
