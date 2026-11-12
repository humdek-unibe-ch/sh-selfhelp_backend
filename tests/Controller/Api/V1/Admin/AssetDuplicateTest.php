<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Tests\Support\AssetFixtures;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\InteractsWithAssetApi;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An upload that has exactly the bytes of an asset already stored is only
 * reported (`duplicates` in the upload response): nothing is refused, both
 * assets exist. The list holds only assets the caller may manage, so it never
 * tells an editor that a file they cannot see exists.
 */
final class AssetDuplicateTest extends QaWebTestCase
{
    use InteractsWithAssetApi;

    private const EDITOR_EMAIL = 'qa.asset.dup.editor@selfhelp.test';
    private const EDITOR_ROLE = 'qa_asset_dup_role';

    protected function tearDown(): void
    {
        $this->removeUploadedAssetFiles();
        parent::tearDown();
    }

    /** A4 */
    public function testTheSameBytesTwiceReportTheFirstAssetAndStoreBoth(): void
    {
        $admin = $this->loginAsQaAdmin();

        $first = $this->uploadAsset($admin, AssetFixtures::upload('qa-dup-first.png', AssetFixtures::png('qa-dup-same')), ['name' => 'qa dup first']);
        self::assertSame([], $first['duplicates'], 'The first copy has no duplicate');

        $second = $this->uploadAsset($admin, AssetFixtures::upload('qa-dup-second.png', AssetFixtures::png('qa-dup-same')), ['name' => 'qa dup second']);
        self::assertSame([['id' => $first['id'], 'name' => 'qa dup first', 'folder_id' => null]], $second['duplicates']);
        self::assertNotSame($first['id'], $second['id']);

        $other = $this->uploadAsset($admin, AssetFixtures::upload('qa-dup-other.png', AssetFixtures::png('qa-dup-different')));
        self::assertSame([], $other['duplicates'], 'Different bytes are not a duplicate');

        // Both exist, and the stored asset row does not carry the report.
        self::assertSame('qa dup first', $this->assetRow($admin, $this->asInt($first['id']))['name']);
        self::assertArrayNotHasKey('duplicates', $this->assetRow($admin, $this->asInt($second['id'])), 'A duplicate report belongs to the upload, not to the asset');
    }

    /** A4 (second half): an identical asset the caller may not manage is not listed. */
    public function testAnIdenticalAssetTheCallerMayNotManageIsNotListed(): void
    {
        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $users = new UserFactory($em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $role = $roles->createRole(self::EDITOR_ROLE);
        foreach (['admin.asset.read', 'admin.asset.create', 'admin.asset.update', 'admin.asset.delete'] as $permission) {
            $roles->grantPermission($role, $permission);
        }
        $users->createUser(self::EDITOR_EMAIL, 'QA Asset Dup Editor', roles: [$role]);

        $admin = $this->loginAsQaAdmin();
        $editor = $this->loginAs(self::EDITOR_EMAIL);

        // The admin uploads it and leaves it to the admin role alone.
        $adminOnly = $this->uploadAsset($admin, AssetFixtures::upload('qa-dup-admin.png', AssetFixtures::png('qa-dup-secret')), ['role_ids' => '']);

        $mine = $this->uploadAsset($editor, AssetFixtures::upload('qa-dup-mine.png', AssetFixtures::png('qa-dup-secret')));
        self::assertSame([], $mine['duplicates'], 'The editor is not told about an asset they cannot manage');

        // The admin may manage both, so they see both.
        $again = $this->uploadAsset($admin, AssetFixtures::upload('qa-dup-again.png', AssetFixtures::png('qa-dup-secret')));
        $ids = array_map(fn (mixed $row): int => $this->asInt($this->asArray($row)['id']), $this->asList($again['duplicates']));
        sort($ids);
        $expected = [$this->asInt($adminOnly['id']), $this->asInt($mine['id'])];
        sort($expected);
        self::assertSame($expected, $ids);
    }
}
