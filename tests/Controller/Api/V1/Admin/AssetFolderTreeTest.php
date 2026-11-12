<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AdminAssetService;
use App\Service\Core\LookupService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\InteractsWithAssetApi;
use App\Tests\Support\QaWebTestCase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The folder rules of the asset library: a folder has a name that is unique
 * among its siblings (any letter case), nests at most 20 levels deep, can only
 * be deleted when it is empty, and the folder list counts assets per subtree
 * in a constant number of queries.
 */
final class AssetFolderTreeTest extends QaWebTestCase
{
    use InteractsWithAssetApi;

    private const EDITOR_EMAIL = 'qa.asset.tree.editor@selfhelp.test';
    private const EDITOR_ROLE = 'qa_asset_tree_role';

    protected function tearDown(): void
    {
        $this->removeUploadedAssetFiles();
        parent::tearDown();
    }

    /** A5 */
    public function testASiblingNameInAnotherLetterCaseIsAConflictAnEmptyNameIsRefusedAndTheSameNameElsewhereIsFine(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parent = $this->createFolder($admin, 'qa-tree-parent');
        $other = $this->createFolder($admin, 'qa-tree-other');
        $this->createFolder($admin, 'qa-tree-Docs', $parent);

        // Create: another case of a sibling's name.
        $this->assertEnvelopeError($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => 'QA-TREE-docs', 'parent_id' => $parent], $admin), 409);
        $this->assertEnvelopeError($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => 'qa-tree-PARENT'], $admin), 409);

        // Rename: onto a sibling's name, and onto its own name in another case (the folder itself is not a sibling).
        $second = $this->createFolder($admin, 'qa-tree-second', $parent);
        $conflict = $this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $second, ['name' => 'qa-tree-DOCS', 'parent_id' => $parent], $admin);
        $this->assertEnvelopeError($conflict, 409);
        self::assertSame("A folder named 'qa-tree-DOCS' already exists here", $conflict['error']);
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $second, ['name' => 'QA-TREE-SECOND', 'parent_id' => $parent], $admin));

        // Moving it next to a sibling of the same name is a conflict too.
        $this->createFolder($admin, 'QA-TREE-SECOND', $other);
        $this->assertEnvelopeError($this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $second, ['name' => 'QA-TREE-SECOND', 'parent_id' => $other], $admin), 409);

        // Empty or blank names are refused.
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => ''], $admin));
        $this->assertEnvelope400($this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => '   '], $admin));

        // The same name under another parent is fine.
        $this->createFolder($admin, 'qa-tree-docs', $other);
    }

    /** The depth limit: 20 levels fit, the 21st is refused, also when a move would push a subtree past it. */
    public function testFoldersNestAtMostTwentyLevelsDeep(): void
    {
        $admin = $this->loginAsQaAdmin();
        $parent = null;
        for ($level = 1; $level <= AdminAssetService::MAX_FOLDER_DEPTH; $level++) {
            $parent = $this->createFolder($admin, 'qa-deep-' . $level, $parent);
        }

        $tooDeep = $this->jsonRequest('POST', '/cms-api/v1/admin/asset-folders', ['name' => 'qa-deep-21', 'parent_id' => $parent], $admin);
        $this->assertEnvelope400($tooDeep);
        self::assertSame('Folders can be nested at most 20 levels deep', $tooDeep['error']);

        // A top-level folder with a child moved under the 19th level would put the child at level 21.
        $branch = $this->createFolder($admin, 'qa-deep-branch');
        $this->createFolder($admin, 'qa-deep-branch-child', $branch);
        $rows = $this->folderRows($admin);
        $nineteenth = null;
        foreach ($rows as $id => $row) {
            if ($row['name'] === 'qa-deep-19') {
                $nineteenth = $id;
            }
        }
        $this->assertEnvelope400($this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $branch, ['name' => 'qa-deep-branch', 'parent_id' => $nineteenth], $admin));
        self::assertNull($this->folderRows($admin)[$branch]['parent_id'], 'The refused move changed nothing');
    }

    /** A6 */
    public function testOnlyAnEmptyFolderIsDeletedAndAnAssetTheCallerCannotSeeCountsToo(): void
    {
        $admin = $this->loginAsQaAdmin();
        $editor = $this->editorToken();

        $parent = $this->createFolder($admin, 'qa-del-parent');
        $child = $this->createFolder($admin, 'qa-del-child', $parent);

        // A subfolder: refused, nothing changed.
        $this->assertEnvelopeError($this->jsonRequest('DELETE', '/cms-api/v1/admin/asset-folders/' . $parent, null, $admin), 409);
        self::assertArrayHasKey($child, $this->folderRows($admin));

        // An asset the editor cannot manage (admin-only) in the child: the editor's delete is refused as well.
        $asset = $this->uploadAsset($admin, AssetFixtures::upload('qa-del-hidden.png'), ['folder_id' => (string) $child, 'role_ids' => '']);
        self::assertSame([], $this->asList($asset['roles']));
        $refused = $this->jsonRequest('DELETE', '/cms-api/v1/admin/asset-folders/' . $child, null, $editor);
        $this->assertEnvelopeError($refused, 409);
        self::assertSame('The folder is not empty', $refused['error']);
        self::assertSame($child, $this->assetRow($admin, $this->asInt($asset['id']))['folder_id'], 'The asset stayed in its folder');
        self::assertArrayHasKey($child, $this->folderRows($admin));

        // Empty again: the child, then the parent, can go.
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/admin/assets/bulk-folder', ['asset_ids' => [$asset['id']], 'folder_id' => null], $admin));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/admin/asset-folders/' . $child, null, $editor));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/admin/asset-folders/' . $parent, null, $admin));
        self::assertArrayNotHasKey($parent, $this->folderRows($admin));
        self::assertArrayNotHasKey($child, $this->folderRows($admin));
    }

    /** A7 */
    public function testAFolderCannotMoveIntoItsDescendantButMovesUnderAnotherFolder(): void
    {
        $admin = $this->loginAsQaAdmin();
        $top = $this->createFolder($admin, 'qa-move-top');
        $child = $this->createFolder($admin, 'qa-move-child', $top);
        $grandchild = $this->createFolder($admin, 'qa-move-grandchild', $child);
        $target = $this->createFolder($admin, 'qa-move-target');

        $this->assertEnvelope400($this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $top, ['name' => 'qa-move-top', 'parent_id' => $grandchild], $admin));
        $this->assertEnvelope400($this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $top, ['name' => 'qa-move-top', 'parent_id' => $top], $admin));

        $envelope = $this->jsonRequest('PUT', '/cms-api/v1/admin/asset-folders/' . $child, ['name' => 'qa-move-child', 'parent_id' => $target], $admin);
        self::assertSame(200, $envelope['status']);
        $moved = [];
        foreach ($this->asList($envelope['data']) as $row) {
            $row = $this->asArray($row);
            $moved[$this->asInt($row['id'])] = $row['parent_id'];
        }
        self::assertSame($target, $moved[$child], 'The returned list shows the new parent');
        self::assertSame($child, $moved[$grandchild], 'Its subfolder moved with it');
    }

    /** A12 */
    public function testTheSubtreeCountIsTheManageableAssetsInAndBelowAndTheQueriesDoNotGrowWithTheTree(): void
    {
        $admin = $this->loginAsQaAdmin();
        $editor = $this->editorToken();

        $top = $this->createFolder($admin, 'qa-count-top');
        $middle = $this->createFolder($admin, 'qa-count-middle', $top);
        $bottom = $this->createFolder($admin, 'qa-count-bottom', $middle);

        $this->uploadAsset($admin, AssetFixtures::upload('qa-count-1.png', AssetFixtures::png('c1')), ['folder_id' => (string) $top]);
        $this->uploadAsset($admin, AssetFixtures::upload('qa-count-2.png', AssetFixtures::png('c2')), ['folder_id' => (string) $bottom]);
        $this->uploadAsset($admin, AssetFixtures::upload('qa-count-3.png', AssetFixtures::png('c3')), ['folder_id' => (string) $bottom]);
        // One the editor manages, one only the admin role does.
        $this->uploadAsset($editor, AssetFixtures::upload('qa-count-4.png', AssetFixtures::png('c4')), ['folder_id' => (string) $middle]);
        $this->uploadAsset($admin, AssetFixtures::upload('qa-count-5.png', AssetFixtures::png('c5')), ['folder_id' => (string) $middle, 'role_ids' => '']);

        $asAdmin = $this->folderRows($admin);
        self::assertSame(1, $asAdmin[$top]['asset_count']);
        self::assertSame(2, $asAdmin[$middle]['asset_count']);
        self::assertSame(5, $asAdmin[$top]['subtree_asset_count'], 'The folder and everything below it');
        self::assertSame(4, $asAdmin[$middle]['subtree_asset_count']);
        self::assertSame(2, $asAdmin[$bottom]['subtree_asset_count']);

        $asEditor = $this->folderRows($editor);
        self::assertSame(1, $asEditor[$middle]['asset_count'], 'Only the assets the caller may manage are counted');
        self::assertSame(1, $asEditor[$top]['subtree_asset_count']);
        self::assertSame(0, $asEditor[$bottom]['subtree_asset_count']);

        // The same number of queries for three folders and for forty more.
        $small = $this->queriesFor('GET', '/cms-api/v1/admin/asset-folders', $admin);
        $parent = $bottom;
        for ($i = 0; $i < 12; $i++) {
            $parent = $this->createFolder($admin, 'qa-count-more-' . $i, $parent);
        }
        for ($i = 0; $i < 30; $i++) {
            $this->createFolder($admin, 'qa-count-flat-' . $i);
        }
        $large = $this->queriesFor('GET', '/cms-api/v1/admin/asset-folders', $admin);
        self::assertSame($small, $large, 'The folder list does not run a query per folder');
    }

    private function queriesFor(string $method, string $uri, string $token): int
    {
        $this->client->enableProfiler();
        $this->client->request($method, $uri, [], [], $this->assetBearer($token));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'The profiler is not available');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function editorToken(): string
    {
        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $role = $roles->createRole(self::EDITOR_ROLE);
        foreach (['admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'] as $permission) {
            $roles->grantPermission($role, $permission);
        }
        (new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class)))
            ->createUser(self::EDITOR_EMAIL, 'QA Asset Tree Editor', roles: [$role]);

        return $this->loginAs(self::EDITOR_EMAIL);
    }
}
