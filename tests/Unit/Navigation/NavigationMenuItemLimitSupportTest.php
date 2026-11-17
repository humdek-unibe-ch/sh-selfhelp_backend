<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Navigation;

use App\Exception\ServiceException;
use App\Navigation\NavigationMenuItemLimitSupport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NavigationMenuItemLimitSupportTest extends TestCase
{
    private NavigationMenuItemLimitSupport $support;

    protected function setUp(): void
    {
        $this->support = new NavigationMenuItemLimitSupport();
    }

    public function testNullMeansUnlimited(): void
    {
        self::assertNull($this->support->normalizeMenuItemLimit(null));
    }

    public function testAcceptsBoundsInclusive(): void
    {
        self::assertSame(1, $this->support->normalizeMenuItemLimit(1));
        self::assertSame(12, $this->support->normalizeMenuItemLimit(12));
        self::assertSame(
            NavigationMenuItemLimitSupport::MAX_ITEM_LIMIT,
            $this->support->normalizeMenuItemLimit(NavigationMenuItemLimitSupport::MAX_ITEM_LIMIT),
        );
    }

    public function testRejectsZeroAndNegative(): void
    {
        $this->expectException(ServiceException::class);
        $this->support->normalizeMenuItemLimit(0);
    }

    public function testRejectsAboveMax(): void
    {
        $this->expectException(ServiceException::class);
        $this->support->normalizeMenuItemLimit(NavigationMenuItemLimitSupport::MAX_ITEM_LIMIT + 1);
    }
}
