<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Navigation;

use App\Navigation\NavigationMenuItemTranslationSupport;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NavigationMenuItemTranslationSupportTest extends TestCase
{
    private NavigationMenuItemTranslationSupport $support;

    protected function setUp(): void
    {
        $this->support = new NavigationMenuItemTranslationSupport(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(NavigationMenuItemTranslationRepository::class),
        );
    }

    public function testResolveLabelPrefersRequestedLanguageThenDefaultThenStored(): void
    {
        self::assertSame(
            'Rechtliches',
            $this->support->resolveLabel(
                [1 => 'Legal', 2 => 'Rechtliches'],
                'Fallback',
                2,
                1,
            ),
        );

        self::assertSame(
            'Legal',
            $this->support->resolveLabel(
                [1 => 'Legal'],
                'Fallback',
                2,
                1,
            ),
        );

        self::assertSame(
            'Fallback',
            $this->support->resolveLabel([], 'Fallback', 2, 1),
        );
    }

    public function testIsTranslatableItemTypeOnlyForGroupAndExternal(): void
    {
        self::assertTrue($this->support->isTranslatableItemType(LookupService::NAVIGATION_ITEM_TYPE_GROUP));
        self::assertTrue($this->support->isTranslatableItemType(LookupService::NAVIGATION_ITEM_TYPE_EXTERNAL_URL));
        self::assertFalse($this->support->isTranslatableItemType(LookupService::NAVIGATION_ITEM_TYPE_PAGE));
    }
}
