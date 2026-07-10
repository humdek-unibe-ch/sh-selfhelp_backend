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
}
