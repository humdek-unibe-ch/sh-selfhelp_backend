<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Service\Core\LookupService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\InteractsWithAssetApi;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /admin/asset-folders/{id}/access` applies groups and/or managing roles,
 * once, to every asset in a folder and all its subfolders. Nothing is stored on
 * the folder, so assets added later are not affected. The call is all or
 * nothing: when one asset in the subtree cannot be updated by the caller (seen
 * or not), nothing changes.
 */
final class AssetFolderAccessTest extends QaWebTestCase
{
    use InteractsWithAssetApi;

    private const EDITOR_EMAIL = 'qa.asset.access.editor@selfhelp.test';
    private const PEER_EMAIL = 'qa.asset.access.peer@selfhelp.test';
    private const EDITOR_ROLE = 'qa_asset_access_editor_role';
    private const PEER_ROLE = 'qa_asset_access_peer_role';
    private const ASSET_PERMISSIONS = ['admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'];

    private int $groupA = 0;
    private int $groupB = 0;
    private int $editorRoleId = 0;
    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $editorRole = $roles->createRole(self::EDITOR_ROLE);
        $peerRole = $roles->createRole(self::PEER_ROLE);
        foreach (self::ASSET_PERMISSIONS as $permission) {
            $roles->grantPermission($editorRole, $permission);
            $roles->grantPermission($peerRole, $permission);
        }
        $users->createUser(self::EDITOR_EMAIL, 'QA Asset Access Editor', roles: [$editorRole]);
        $users->createUser(self::PEER_EMAIL, 'QA Asset Access Peer', roles: [$peerRole]);
        $this->editorRoleId = (int) $editorRole->getId();
        $groups = new GroupFactory($em);
        $this->groupA = (int) $groups->createGroup('qa_asset_access_group_a')->getId();
        $this->groupB = (int) $groups->createGroup('qa_asset_access_group_b')->getId();
    }

    protected function tearDown(): void
    {
        $this->removeUploadedAssetFiles();
        parent::tearDown();
    }

    /** A8 */
    public function testGroupsAreSetOnEveryAssetInTheFolderAndBelowAndNothingOutsideChanges(): void
    {
        $admin = $this->loginAsQaAdmin();
        $top = $this->createFolder($admin, 'qa-access-top');
        $sub = $this->createFolder($admin, 'qa-access-sub', $top);
        $beside = $this->createFolder($admin, 'qa-access-beside');

        $inTop = $this->uploadAsset($admin, AssetFixtures::upload('qa-access-1.png', AssetFixtures::png('a1')), ['folder_id' => (string) $top]);
        $inSub = $this->uploadAsset($admin, AssetFixtures::upload('qa-access-2.png', AssetFixtures::png('a2')), ['folder_id' => (string) $sub]);
        $inSub2 = $this->uploadAsset($admin, AssetFixtures::upload('qa-access-3.png', AssetFixtures::png('a3')), ['folder_id' => (string) $sub]);
        $outside = $this->uploadAsset($admin, AssetFixtures::upload('qa-access-4.png', AssetFixtures::png('a4')), ['folder_id' => (string) $beside, 'group_ids' => (string) $this->groupB]);
        $loose = $this->uploadAsset($admin, AssetFixtures::upload('qa-access-5.png', AssetFixtures::png('a5')));

        $result = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => [$this->groupA]], $admin));
        self::assertSame(['updated' => 3], $result);

        foreach ([$inTop, $inSub, $inSub2] as $asset) {
            self::assertSame(['qa_asset_access_group_a'], $this->assetGroupNames($this->assetRow($admin, $this->asInt($asset['id']))));
        }
        self::assertSame(['qa_asset_access_group_b'], $this->assetGroupNames($this->assetRow($admin, $this->asInt($outside['id']))), 'An asset outside the subtree is untouched');
        self::assertSame([], $this->assetGroupNames($this->assetRow($admin, $this->asInt($loose['id']))));

        // The admin list shows the new groups too: the cached list was cleared.
        $list = $this->assertEnvelopeSuccess($this->jsonRequest('GET', '/cms-api/v1/admin/assets?folder_id=' . $sub, null, $admin));
        foreach ($this->asList($list['assets']) as $row) {
            self::assertSame(['qa_asset_access_group_a'], $this->assetGroupNames($this->asArray($row)));
        }

        // `group_ids: []` makes them public again; the roles were not sent, so they are as they were.
        $rolesBefore = $this->asList($this->assetRow($admin, $this->asInt($inSub['id']))['roles']);
        self::assertSame(['updated' => 3], $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => []], $admin)));
        foreach ([$inTop, $inSub, $inSub2] as $asset) {
            self::assertSame([], $this->assetGroupNames($this->assetRow($admin, $this->asInt($asset['id']))));
        }
        self::assertSame($rolesBefore, $this->asList($this->assetRow($admin, $this->asInt($inSub['id']))['roles']));

        // Roles on their own: replaced on every asset, groups untouched.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => [$this->groupB]], $admin));
        self::assertSame(['updated' => 3], $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['role_ids' => [$this->editorRoleId]], $admin)));
        foreach ([$inTop, $inSub, $inSub2] as $asset) {
            $row = $this->assetRow($admin, $this->asInt($asset['id']));
            self::assertSame([self::EDITOR_ROLE], array_map(fn (mixed $role): mixed => $this->asArray($role)['name'], $this->asList($row['roles'])));
            self::assertSame(['qa_asset_access_group_b'], $this->assetGroupNames($row), 'Sending only roles leaves the groups');
        }

        // A folder with no asset answers 0.
        $empty = $this->createFolder($admin, 'qa-access-empty');
        self::assertSame(['updated' => 0], $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $empty . '/access', ['group_ids' => [$this->groupA]], $admin)));
    }

    /** A9 */
    public function testAnAssetTheCallerCannotUpdateRefusesTheWholeCallAndEveryInputErrorIsAnswered(): void
    {
        $admin = $this->loginAsQaAdmin();
        $editor = $this->loginAs(self::EDITOR_EMAIL);
        $peer = $this->loginAs(self::PEER_EMAIL);

        $top = $this->createFolder($admin, 'qa-refuse-top');
        $sub = $this->createFolder($admin, 'qa-refuse-sub', $top);
        $mine = $this->uploadAsset($editor, AssetFixtures::upload('qa-refuse-mine.png', AssetFixtures::png('r1')), ['folder_id' => (string) $top]);
        $alsoMine = $this->uploadAsset($editor, AssetFixtures::upload('qa-refuse-mine2.png', AssetFixtures::png('r2')), ['folder_id' => (string) $sub]);
        $theirs = $this->uploadAsset($peer, AssetFixtures::upload('qa-refuse-theirs.png', AssetFixtures::png('r3')), ['folder_id' => (string) $sub]);

        // The subtree holds one asset of another role: 403 and nothing changed.
        $this->assertEnvelope403($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => [$this->groupA]], $editor));
        foreach ([$mine, $alsoMine, $theirs] as $asset) {
            self::assertSame([], $this->assetGroupNames($this->assetRow($admin, $this->asInt($asset['id']))), 'Not even the assets the editor may change were changed');
        }

        // Without the foreign asset, the editor's own subtree works.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-folder', ['asset_ids' => [$theirs['id']], 'folder_id' => null], $peer));
        self::assertSame(['updated' => 2], $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => [$this->groupA]], $editor)));

        // An empty role list would leave the assets to an admin alone: only an admin may send it.
        $emptied = $this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['role_ids' => []], $editor);
        $this->assertEnvelope400($emptied);
        self::assertStringContainsString('at least one managing role', $this->asString($emptied['error']));
        self::assertCount(1, $this->asList($this->assetRow($admin, $this->asInt($mine['id']))['roles']), 'The editor role still manages it');
        // The admin role alone is the same as an empty list.
        $adminRole = $this->service(EntityManagerInterface::class)->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Role::class, $adminRole);
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['role_ids' => [$adminRole->getId()]], $editor));
        // An admin may.
        self::assertSame(['updated' => 2], $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['role_ids' => []], $admin)));
        self::assertSame([], $this->asList($this->assetRow($admin, $this->asInt($mine['id']))['roles']));

        // Neither key, and keys of the wrong shape: 400. An unknown folder: 404.
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', [], $admin));
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => 'qa'], $admin));
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['other' => [1]], $admin));
        $this->assertEnvelope404($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/2147483600/access', ['group_ids' => []], $admin));
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders/' . $top . '/access', ['group_ids' => [2147483600]], $admin));
    }
}
