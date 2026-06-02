<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Repository\ActionRepository;
use App\Service\Action\ActionResolverService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the action resolver. The resolver must pass the table + trigger
 * straight through to the repository and return exactly what it finds.
 */
final class ActionResolverServiceTest extends TestCase
{
    public function testResolveDelegatesToRepositoryWithTableAndTrigger(): void
    {
        $dataTable = $this->createStub(DataTable::class);
        $action = $this->createStub(Action::class);

        $repository = $this->createMock(ActionRepository::class);
        $repository->expects(self::once())
            ->method('findByDataTableAndTrigger')
            ->with($dataTable, 'finished')
            ->willReturn([$action]);

        $resolved = (new ActionResolverService($repository))->resolve($dataTable, 'finished');

        self::assertSame([$action], $resolved);
    }

    public function testResolveReturnsEmptyArrayWhenNoActionsMatch(): void
    {
        $dataTable = $this->createStub(DataTable::class);
        $repository = $this->createStub(ActionRepository::class);
        $repository->method('findByDataTableAndTrigger')->willReturn([]);

        self::assertSame([], (new ActionResolverService($repository))->resolve($dataTable, 'updated'));
    }
}
