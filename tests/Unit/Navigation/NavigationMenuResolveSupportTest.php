<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Navigation;

use App\Navigation\NavigationMenuResolveSupport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NavigationMenuResolveSupportTest extends TestCase
{
    public function testApplyRootItemLimitKeepsLowestPositions(): void
    {
        $items = [
            ['id' => 3, 'position' => 30, 'label' => 'c'],
            ['id' => 1, 'position' => 10, 'label' => 'a'],
            ['id' => 2, 'position' => 20, 'label' => 'b'],
            ['id' => 4, 'position' => 40, 'label' => 'd'],
        ];

        $limited = NavigationMenuResolveSupport::applyRootItemLimit($items, 3);

        self::assertCount(3, $limited);
        self::assertSame([1, 2, 3], array_column($limited, 'id'));
    }

    public function testApplyRootItemLimitNullMeansUnlimited(): void
    {
        $items = [
            ['id' => 1, 'position' => 10],
            ['id' => 2, 'position' => 20],
        ];

        self::assertSame($items, NavigationMenuResolveSupport::applyRootItemLimit($items, null));
    }

    public function testHiddenAutoIncludeChildrenDetectsAclFilteredPages(): void
    {
        $authoringChildren = [
            ['id' => 10, 'keyword' => 'visible-child'],
            ['id' => 11, 'keyword' => 'hidden-child'],
            ['id' => 12, 'keyword' => 'headless-child', 'is_headless' => true],
        ];
        $publicMap = [
            10 => ['id' => 10, 'keyword' => 'visible-child'],
        ];

        $hidden = NavigationMenuResolveSupport::hiddenAutoIncludeChildren($authoringChildren, $publicMap, []);

        self::assertCount(2, $hidden);
        self::assertSame('hidden-child', $hidden[0]['keyword']);
        self::assertSame('headless-child', $hidden[1]['keyword']);
    }

    public function testHiddenAutoIncludeChildrenRespectsExclusions(): void
    {
        $authoringChildren = [
            ['id' => 20, 'keyword' => 'excluded'],
        ];

        $hidden = NavigationMenuResolveSupport::hiddenAutoIncludeChildren(
            $authoringChildren,
            [],
            [20],
        );

        self::assertSame([], $hidden);
    }

    public function testSuggestedManualChildrenListsOnlyMissingExplicitItems(): void
    {
        $authoringChildren = [
            ['id' => 1, 'keyword' => 'already-in-menu'],
            ['id' => 2, 'keyword' => 'suggested'],
        ];

        $suggestions = NavigationMenuResolveSupport::suggestedManualChildren($authoringChildren, [1]);

        self::assertCount(1, $suggestions);
        self::assertSame(2, $suggestions[0]['page_id']);
        self::assertSame('suggested', $suggestions[0]['keyword']);
    }
}
