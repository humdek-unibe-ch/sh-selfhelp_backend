<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\System;

use App\Entity\System\SystemUpdateOperation;
use App\Exception\ServiceException;
use App\Repository\Plugin\PluginRepository;
use App\Repository\System\SystemUpdateOperationRepository;
use App\Service\Auth\UserContextService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemRegistryGatewayInterface;
use App\Service\System\SystemUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend half of the CMS <-> Manager update loop (CRITICAL 3) plus the
 * cross-instance guard wiring (LOW 1). These exercise the pure service logic
 * with stubbed persistence (no database), so they prove instance scoping,
 * status validation, and terminal-state protection deterministically.
 */
final class SystemUpdateServiceManagerLoopTest extends TestCase
{
    private const INSTANCE = 'inst-a';

    private function makeService(
        SystemUpdateOperationRepository $operations,
        ?SystemRegistryGatewayInterface $registry = null,
    ): SystemUpdateService {
        $instance = new SystemInstanceService(self::INSTANCE, '8.0.0', '2.1', '8.0.0', false, false);

        $plugins = $this->createStub(PluginRepository::class);
        $plugins->method('findAllOrderedByName')->willReturn([]);

        $registryStub = $registry ?? $this->createStub(SystemRegistryGatewayInterface::class);

        $userContext = $this->createStub(UserContextService::class);
        $userContext->method('getActualUserId')->willReturn(0);

        return new SystemUpdateService(
            $instance,
            $operations,
            $plugins,
            $registryStub,
            $userContext,
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    public function testDenyCrossInstanceIgnoresAbsentInstanceValue(): void
    {
        $service = $this->makeService($this->createStub(SystemUpdateOperationRepository::class));
        $service->denyCrossInstance(null);
        // No exception means an absent client instance id is correctly in-scope.
        self::assertTrue(true);
    }

    public function testDenyCrossInstanceRejectsAnyClientSuppliedInstanceId(): void
    {
        $service = $this->makeService($this->createStub(SystemUpdateOperationRepository::class));
        try {
            $service->denyCrossInstance('inst-evil');
            self::fail('Expected a cross-instance ServiceException.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_FORBIDDEN, $e->getCode());
        }
    }

    public function testRecordManagerStatusRejectsAnInvalidStatus(): void
    {
        $service = $this->makeService($this->createStub(SystemUpdateOperationRepository::class));
        try {
            $service->recordManagerStatus('op_1', 'not_a_real_status', 10);
            self::fail('Expected a ServiceException for an invalid status.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    public function testRecordManagerStatusRejectsUnknownOperation(): void
    {
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findByOperationId')->willReturn(null);

        $service = $this->makeService($operations);
        try {
            $service->recordManagerStatus('op_missing', 'accepted', 5);
            self::fail('Expected a ServiceException for an unknown operation.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_NOT_FOUND, $e->getCode());
        }
    }

    public function testRecordManagerStatusRejectsCrossInstanceOperation(): void
    {
        $foreign = new SystemUpdateOperation('inst-other', 'op_1', '8.0.1');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findByOperationId')->willReturn($foreign);

        $service = $this->makeService($operations);
        try {
            $service->recordManagerStatus('op_1', 'accepted', 5);
            self::fail('Expected a 404 for an operation owned by another instance.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_NOT_FOUND, $e->getCode());
        }
    }

    public function testRecordManagerStatusRejectsTerminalOperation(): void
    {
        $done = new SystemUpdateOperation(self::INSTANCE, 'op_1', '8.0.1');
        $done->setStatus(SystemUpdateOperation::STATUS_SUCCEEDED);
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findByOperationId')->willReturn($done);

        $service = $this->makeService($operations);
        try {
            $service->recordManagerStatus('op_1', 'update_running', 50);
            self::fail('Expected a 409 for a terminal operation.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_CONFLICT, $e->getCode());
        }
    }

    public function testRecordManagerStatusUpdatesAnInScopeOperation(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_1', '8.0.1');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findByOperationId')->willReturn($operation);

        $service = $this->makeService($operations);
        $result = $service->recordManagerStatus(
            'op_1',
            SystemUpdateOperation::STATUS_MIGRATION_RUNNING,
            70,
            [['name' => 'migrate', 'status' => 'done']],
            'running migrations',
        );

        self::assertSame('migration_running', $result['status']);
        self::assertSame(70, $result['progress_percent']);
        self::assertSame('migration_running', $operation->getStatus());
        self::assertSame('running migrations', $operation->getMessage());
        self::assertSame([['name' => 'migrate', 'status' => 'done']], $operation->getStepsJson());
    }

    public function testClaimPendingReturnsNullWhenNothingIsClaimable(): void
    {
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestClaimableForInstance')->willReturn(null);

        self::assertNull($this->makeService($operations)->claimPendingOperation());
    }

    public function testClaimPendingExposesDestructiveFlagFromRegistry(): void
    {
        $operation = new SystemUpdateOperation(self::INSTANCE, 'op_42', '8.0.1');
        $operation->setAcceptedMigrationRisk(true)->setPreflightId('pf_42');
        $operations = $this->createStub(SystemUpdateOperationRepository::class);
        $operations->method('findLatestClaimableForInstance')->willReturn($operation);

        $registry = $this->createStub(SystemRegistryGatewayInterface::class);
        $registry->method('fetchCoreRelease')->willReturn([
            'database' => ['destructive' => true, 'requiresBackup' => true, 'manualConfirmationRequired' => true],
        ]);

        $dto = $this->makeService($operations, $registry)->claimPendingOperation();

        self::assertNotNull($dto);
        self::assertSame('op_42', $dto['operation_id']);
        self::assertSame(self::INSTANCE, $dto['instance_id']);
        self::assertSame('8.0.1', $dto['target_version']);
        // The operation id doubles as the approval token in this trust model.
        self::assertSame('op_42', $dto['approval_token']);
        self::assertTrue($dto['accepted_migration_risk']);
        self::assertTrue($dto['destructive_migration']);
    }
}
