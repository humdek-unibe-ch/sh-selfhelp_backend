<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Admin;

use App\Service\CMS\Admin\AssetAccessService;
use App\Service\Security\DataAccessSecurityService;
use PHPUnit\Framework\TestCase;

/**
 * The per-asset rights the CMS draws from (`can_view`, `can_update`,
 * `can_delete`): the decision alone, with its facts handed in. The end-to-end
 * check, that each flag matches what the server then accepts, is in
 * AssetManagementAccessTest.
 */
final class AssetAccessRightsTest extends TestCase
{
    private const READ = DataAccessSecurityService::PERMISSION_READ;
    private const UPDATE = DataAccessSecurityService::PERMISSION_UPDATE;
    private const DELETE = DataAccessSecurityService::PERMISSION_DELETE;

    public function testTheAdminRoleMayDoEverythingWithEveryAsset(): void
    {
        $rights = AssetAccessService::decideRights([1 => [], 2 => [7]], true, [], []);

        $all = ['can_view' => true, 'can_update' => true, 'can_delete' => true];
        self::assertSame([1 => $all, 2 => $all], $rights);
    }

    public function testAPublicAssetCanBeViewedByAnyoneButManagedOnlyWithAGrant(): void
    {
        $rights = AssetAccessService::decideRights([1 => []], false, [1 => self::READ], []);

        self::assertSame(['can_view' => true, 'can_update' => false, 'can_delete' => false], $rights[1]);
    }

    public function testAnAssetWithGroupsCanBeViewedOnlyByAMemberOfOneOfThem(): void
    {
        $rights = AssetAccessService::decideRights(
            [1 => [5, 6], 2 => [8]],
            false,
            [],
            [6, 9],
        );

        self::assertTrue($rights[1]['can_view'], 'A member of ANY of the groups may view it');
        self::assertFalse($rights[2]['can_view'], 'A caller in none of the groups may not');
    }

    public function testUpdateAndDeleteFollowTheirOwnGrantBitsPerAsset(): void
    {
        $rights = AssetAccessService::decideRights(
            [1 => [], 2 => [], 3 => [], 4 => []],
            false,
            [
                1 => self::READ | self::UPDATE,
                2 => self::READ | self::DELETE,
                3 => AssetAccessService::FULL_ACCESS,
                // asset 4: no grant at all
            ],
            [],
        );

        self::assertSame(['can_view' => true, 'can_update' => true, 'can_delete' => false], $rights[1]);
        self::assertSame(['can_view' => true, 'can_update' => false, 'can_delete' => true], $rights[2]);
        self::assertSame(['can_view' => true, 'can_update' => true, 'can_delete' => true], $rights[3]);
        self::assertSame(['can_view' => true, 'can_update' => false, 'can_delete' => false], $rights[4]);
    }

    public function testViewingAndManagingAreIndependent(): void
    {
        // Managing an asset says nothing about viewing it, and the reverse.
        $rights = AssetAccessService::decideRights(
            [1 => [5], 2 => [5]],
            false,
            [1 => AssetAccessService::FULL_ACCESS],
            [5],
        );

        self::assertSame(['can_view' => true, 'can_update' => true, 'can_delete' => true], $rights[1]);
        self::assertSame(['can_view' => true, 'can_update' => false, 'can_delete' => false], $rights[2], 'In the group, but no grant');

        $outsider = AssetAccessService::decideRights([1 => [5]], false, [1 => AssetAccessService::FULL_ACCESS], []);
        self::assertSame(['can_view' => false, 'can_update' => true, 'can_delete' => true], $outsider[1], 'Manages it, but cannot open the file');
    }

    public function testNoAssetsGivesNoRights(): void
    {
        self::assertSame([], AssetAccessService::decideRights([], false, [], []));
    }
}
