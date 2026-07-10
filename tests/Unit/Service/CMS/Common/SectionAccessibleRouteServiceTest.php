<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Common;

use App\Entity\PageRoute;
use App\Repository\PageRouteRepository;
use App\Repository\SectionRepository;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Common\SectionAccessibleRouteService;
use App\Service\Security\DataAccessSecurityService;
use PHPUnit\Framework\TestCase;

final class SectionAccessibleRouteServiceTest extends TestCase
{
    public function testAccessiblePageExposesRoutePlaceholdersAndRequirements(): void
    {
        $service = $this->serviceWithAccess(grantPageRead: true);

        self::assertSame(['record_id'], $service->getRoutePlaceholdersForSection(12, 55));
        self::assertSame(['record_id' => '\\d+'], $service->getRouteRequirementsForSection(12, 55));
    }

    public function testInaccessiblePageHidesRoutePlaceholdersAndRequirements(): void
    {
        $service = $this->serviceWithAccess(grantPageRead: false);

        self::assertSame([], $service->getRoutePlaceholdersForSection(12, 55));
        self::assertSame([], $service->getRouteRequirementsForSection(12, 55));
    }

    public function testNullUserIdNormalizesToGuestUserId(): void
    {
        $security = $this->createMock(DataAccessSecurityService::class);
        $security->expects(self::once())
            ->method('hasPermission')
            ->with(
                UserContextService::GUEST_USER_ID,
                'pages',
                7,
                DataAccessSecurityService::PERMISSION_READ,
            )
            ->willReturn(false);

        $sectionRepository = $this->createMock(SectionRepository::class);
        $sectionRepository->method('getPagesContainingSections')->willReturn([['id' => 7]]);

        $pageRouteRepository = $this->createMock(PageRouteRepository::class);

        $service = new SectionAccessibleRouteService($sectionRepository, $pageRouteRepository, $security);
        self::assertSame([], $service->getRoutePlaceholdersForSection(12, null));
    }

    private function serviceWithAccess(bool $grantPageRead): SectionAccessibleRouteService
    {
        $security = $this->createMock(DataAccessSecurityService::class);
        $security->method('hasPermission')
            ->with(55, 'pages', 7, DataAccessSecurityService::PERMISSION_READ)
            ->willReturn($grantPageRead);

        $sectionRepository = $this->createMock(SectionRepository::class);
        $sectionRepository->method('getPagesContainingSections')->willReturn([['id' => 7]]);

        $route = $this->createMock(PageRoute::class);
        $route->method('isActive')->willReturn(true);
        $route->method('getPathPattern')->willReturn('/news/{record_id}');
        $route->method('getRequirements')->willReturn(['record_id' => '\\d+']);

        $pageRouteRepository = $this->createMock(PageRouteRepository::class);
        $pageRouteRepository->method('findByPageId')->with(7)->willReturn([$route]);

        return new SectionAccessibleRouteService($sectionRepository, $pageRouteRepository, $security);
    }
}
