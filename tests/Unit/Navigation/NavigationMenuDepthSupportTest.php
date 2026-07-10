<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Navigation;

use App\Entity\NavigationMenuItem;
use App\Navigation\NavigationMenuDepthSupport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NavigationMenuDepthSupportTest extends TestCase
{
    private NavigationMenuDepthSupport $support;

    protected function setUp(): void
    {
        $this->support = new NavigationMenuDepthSupport();
    }

    public function testTopLevelItemHasDepthZero(): void
    {
        $item = new NavigationMenuItem();
        self::assertSame(0, $this->support->computeDepth($item));
        self::assertTrue($this->support->isDepthAllowed(null));
    }

    public function testChildItemHasDepthOne(): void
    {
        $parent = new NavigationMenuItem();
        $child = (new NavigationMenuItem())->setParentItem($parent);
        self::assertSame(1, $this->support->computeDepth($child));
        self::assertTrue($this->support->isDepthAllowed($parent));
    }

    public function testGrandchildDepthIsAllowed(): void
    {
        $root = new NavigationMenuItem();
        $child = (new NavigationMenuItem())->setParentItem($root);
        self::assertSame(1, $this->support->computeDepth($child));
        self::assertTrue($this->support->isDepthAllowed($child));
    }

    public function testGreatGrandchildDepthIsRejected(): void
    {
        $root = new NavigationMenuItem();
        $child = (new NavigationMenuItem())->setParentItem($root);
        $grandchild = (new NavigationMenuItem())->setParentItem($child);
        self::assertSame(2, $this->support->computeDepth($grandchild));
        self::assertFalse($this->support->isDepthAllowed($grandchild));
    }

    public function testMenuMaxDepthIsCappedAtTwo(): void
    {
        self::assertSame(2, $this->support->normalizeMenuMaxDepth(null));
        self::assertSame(2, $this->support->normalizeMenuMaxDepth(5));
        self::assertSame(2, $this->support->normalizeMenuMaxDepth(2));
        self::assertSame(1, $this->support->normalizeMenuMaxDepth(1));
    }
}
