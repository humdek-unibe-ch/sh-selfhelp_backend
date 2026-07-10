<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Admin;

use App\Entity\DataTable;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminSectionService;
use App\Service\CMS\Admin\DataQueryPreviewService;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\Common\SectionAccessibleRouteService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Core\InterpolationService;
use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;

final class DataQueryPreviewServiceTest extends TestCase
{
    use NarrowsJson;
    public function testPreviewNormalizesFilterWithRouteParams(): void
    {
        $user = $this->createMock(\App\Entity\User::class);
        $user->method('getId')->willReturn(42);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getCurrentUser')->willReturn($user);

        $dataTable = $this->createMock(DataTable::class);
        $dataTable->method('getId')->willReturn(9);
        $dataTable->method('getName')->willReturn('230');
        $dataTable->method('getDisplayName')->willReturn('Team members');

        $dataService = $this->createMock(DataService::class);
        $dataService->method('getDataTableById')->with(9)->willReturn($dataTable);

        $dataTableService = $this->createMock(DataTableService::class);
        $dataTableService->method('canAccessDataTable')->willReturn(true);
        $dataTableService->method('getColumns')->willReturn([
            ['id' => null, 'fieldKey' => 'record_id', 'displayName' => 'Record ID', 'locked' => true, 'standard' => true],
        ]);

        $routeService = $this->createMock(SectionAccessibleRouteService::class);

        $service = new DataQueryPreviewService(
            $this->createMock(AdminSectionService::class),
            new DataTableFilterService(new InterpolationService()),
            $dataTableService,
            $dataService,
            $userContext,
            $routeService,
        );

        $result = $service->preview([
            'data_table' => 9,
            'filter' => 'AND record_id = {{route.record_id}}',
            'route_params' => ['record_id' => '7'],
        ]);

        self::assertSame('AND record_id = 7', $result['prepared_filter']);
        self::assertSame([], $result['errors']);
        $storedProcedure = self::asArray($result['stored_procedure'] ?? null);
        self::assertStringContainsString('get_data_table_filtered', self::coerceString($storedProcedure['call'] ?? null));
    }

    public function testPreviewDelegatesRouteDiscoveryToAccessibleRouteService(): void
    {
        $user = $this->createMock(\App\Entity\User::class);
        $user->method('getId')->willReturn(42);

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getCurrentUser')->willReturn($user);

        $dataTable = $this->createMock(DataTable::class);
        $dataTable->method('getId')->willReturn(9);
        $dataTable->method('getName')->willReturn('230');
        $dataTable->method('getDisplayName')->willReturn('Team members');

        $dataService = $this->createMock(DataService::class);
        $dataService->method('getDataTableById')->with(9)->willReturn($dataTable);

        $dataTableService = $this->createMock(DataTableService::class);
        $dataTableService->method('canAccessDataTable')->willReturn(true);
        $dataTableService->method('getColumns')->willReturn([]);

        $adminSectionService = $this->createMock(AdminSectionService::class);
        $adminSectionService->method('getSection')->willReturn([
            'fields' => [
                [
                    'name' => 'data_table',
                    'translations' => [['language_id' => 1, 'content' => '9']],
                ],
                [
                    'name' => 'filter',
                    'translations' => [['language_id' => 1, 'content' => 'AND record_id = {{route.record_id}}']],
                ],
            ],
        ]);

        $routeService = $this->createMock(SectionAccessibleRouteService::class);
        $routeService->expects(self::once())
            ->method('getRoutePlaceholdersForSection')
            ->with(15, 42)
            ->willReturn(['record_id']);
        $routeService->expects(self::once())
            ->method('getRouteRequirementsForSection')
            ->with(15, 42)
            ->willReturn(['record_id' => '\\d+']);

        $service = new DataQueryPreviewService(
            $adminSectionService,
            new DataTableFilterService(new InterpolationService()),
            $dataTableService,
            $dataService,
            $userContext,
            $routeService,
        );

        $result = $service->preview(['section_id' => 15]);

        self::assertSame(['record_id' => '1'], $result['route_params']);
        self::assertSame(['record_id' => '\\d+'], $result['route_requirements']);
    }
}
