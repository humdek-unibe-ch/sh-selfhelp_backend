<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\System;

use App\Entity\System\SystemUpdateOperation;
use App\Exception\ServiceException;
use App\Service\System\SystemUpdateService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * DB-backed round-trip of the CMS <-> SelfHelp Manager update loop
 * ({@see SystemUpdateService}, distribution/update execution).
 *
 * The pure service logic is unit-tested with stubbed persistence in
 * {@see \App\Tests\Unit\Service\System\SystemUpdateServiceManagerLoopTest}; this
 * test instead drives the SAME service wired to the REAL repository + entity
 * manager + registry gateway against the seeded QA database, so it proves the
 * things a stub cannot:
 *   - `requestUpdate` actually persists a `requested` operation;
 *   - `findLatestClaimableForInstance` selects exactly that operation (and only
 *     while it is `requested`);
 *   - manager status write-backs flush through to MySQL and JSON columns
 *     round-trip;
 *   - the terminal guard is enforced against the persisted state, not just an
 *     in-memory object;
 *   - the status the admin UI polls reflects the manager's final write.
 *
 * The registry is unreachable in CI, so a far-future target keeps the recomputed
 * preflight at `warning` (never `blocked`) and the request deterministic. DAMA
 * rolls back every operation row after each test.
 *
 * Grouped `security` because it proves the manager loop's security model
 * end-to-end against the real database — instance-scoped claims, terminal-state
 * protection, and the fact that one instance can never re-drive a finished
 * operation — and so runs in the `--group=security` gate
 * (`.github/workflows/backend-tests.yml`).
 */
#[Group('security')]
final class SystemUpdateManagerLoopRoundTripTest extends QaKernelTestCase
{
    private const TARGET = '999.0.0';

    private SystemUpdateService $updates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->updates = $this->service(SystemUpdateService::class);
    }

    public function testRequestedThroughClaimToSucceededRoundTrip(): void
    {
        // Nothing is claimable for this instance before anything is requested.
        self::assertNull(
            $this->updates->claimPendingOperation(),
            'A fresh instance has no claimable operation.'
        );

        // 1. CMS records the operator's intent: status `requested`.
        $requested = $this->updates->requestUpdate([
            'target_version' => self::TARGET,
            'preflight_id' => 'pf_roundtrip',
            'accepted_migration_risk' => false,
        ]);
        $operationId = $requested['operation_id'];
        $instanceId = $requested['instance_id'];
        self::assertNotSame('', $operationId);
        self::assertNotSame('', $instanceId);
        self::assertSame(SystemUpdateOperation::STATUS_REQUESTED, $requested['status']);

        // 2. The manager claims the next pending operation for THIS instance.
        $claim = $this->updates->claimPendingOperation();
        self::assertNotNull($claim, 'The just-requested operation must be claimable.');
        self::assertSame($operationId, $claim['operation_id']);
        self::assertSame($instanceId, $claim['instance_id']);
        self::assertSame(self::TARGET, $claim['target_version']);
        // The operation id doubles as the single-use approval token.
        self::assertSame($operationId, $claim['approval_token']);
        self::assertFalse($claim['accepted_migration_risk']);

        // 3. The manager walks the lifecycle, each write flushing to the DB.
        $lifecycle = [
            [SystemUpdateOperation::STATUS_ACCEPTED, 5],
            [SystemUpdateOperation::STATUS_BACKUP_RUNNING, 20],
            [SystemUpdateOperation::STATUS_UPDATE_RUNNING, 50],
            [SystemUpdateOperation::STATUS_MIGRATION_RUNNING, 70],
            [SystemUpdateOperation::STATUS_HEALTH_CHECK_RUNNING, 90],
        ];
        foreach ($lifecycle as [$status, $percent]) {
            $ack = $this->updates->recordManagerStatus($operationId, $status, $percent);
            self::assertSame($status, $ack['status']);
            self::assertSame($percent, $ack['progress_percent']);
            self::assertSame($operationId, $ack['operation_id']);
        }

        // Final write carries the executed steps so we also prove the JSON column
        // round-trips through MySQL.
        $steps = [
            ['name' => 'backup', 'status' => 'succeeded'],
            ['name' => 'migrate', 'status' => 'succeeded'],
            ['name' => 'health_check', 'status' => 'succeeded'],
        ];
        $done = $this->updates->recordManagerStatus(
            $operationId,
            SystemUpdateOperation::STATUS_SUCCEEDED,
            100,
            $steps,
            'Update completed by the SelfHelp Manager.'
        );
        self::assertSame(SystemUpdateOperation::STATUS_SUCCEEDED, $done['status']);
        self::assertSame(100, $done['progress_percent']);

        // 4. The status the admin UI polls reflects the manager's final write.
        $status = $this->updates->getStatus();
        self::assertSame($operationId, $status['operation_id']);
        self::assertSame(SystemUpdateOperation::STATUS_SUCCEEDED, $status['status']);
        self::assertSame(self::TARGET, $status['target_version']);
        self::assertSame(100, $status['progress_percent']);
        self::assertSame($steps, $status['steps'], 'Steps JSON must round-trip through the database.');
        self::assertSame('Update completed by the SelfHelp Manager.', $status['message']);

        // 5. A terminal operation is no longer claimable...
        self::assertNull(
            $this->updates->claimPendingOperation(),
            'A succeeded operation must not be re-claimable.'
        );

        // ...and further manager write-backs are rejected with 409 (enforced
        // against the persisted terminal state).
        try {
            $this->updates->recordManagerStatus($operationId, SystemUpdateOperation::STATUS_UPDATE_RUNNING, 50);
            self::fail('Expected a 409 for a write-back to a terminal operation.');
        } catch (ServiceException $e) {
            self::assertSame(Response::HTTP_CONFLICT, $e->getCode());
        }
    }

    public function testFrontendRequestThroughClaimCarriesTheFrontendKindRoundTrip(): void
    {
        // The frontend ships independently of the core: an instance can request a
        // frontend-only update. It must persist with kind=frontend + the frontend
        // target, and the claim DTO the manager reads must carry both so it takes
        // the lightweight (stateless) path. A far-future target keeps the
        // recomputed preflight non-blocked offline.
        $requested = $this->updates->requestFrontendUpdate([
            'target_version' => self::TARGET,
            'preflight_id' => 'pff_roundtrip',
        ]);
        $operationId = $requested['operation_id'];
        self::assertNotSame('', $operationId);
        self::assertSame('frontend', $requested['kind']);
        self::assertSame(self::TARGET, $requested['target_frontend_version']);
        self::assertSame(SystemUpdateOperation::STATUS_REQUESTED, $requested['status']);

        $claim = $this->updates->claimPendingOperation();
        self::assertNotNull($claim, 'The just-requested frontend operation must be claimable.');
        self::assertSame($operationId, $claim['operation_id']);
        self::assertSame('frontend', $claim['kind']);
        self::assertSame(self::TARGET, $claim['target_frontend_version']);
        // A stateless frontend swap never carries destructive migrations.
        self::assertFalse($claim['destructive_migration']);

        // The status the admin UI polls reflects the frontend kind.
        $status = $this->updates->getStatus();
        self::assertSame($operationId, $status['operation_id']);
        self::assertSame('frontend', $status['kind']);
        self::assertSame(self::TARGET, $status['target_frontend_version']);
    }

    public function testFailedUpdateRecordsTerminalFailureForTheInstance(): void
    {
        $requested = $this->updates->requestUpdate([
            'target_version' => self::TARGET,
            'preflight_id' => 'pf_fail',
            'accepted_migration_risk' => false,
        ]);
        $operationId = $requested['operation_id'];

        $this->updates->recordManagerStatus($operationId, SystemUpdateOperation::STATUS_BACKUP_RUNNING, 20);
        $this->updates->recordManagerStatus(
            $operationId,
            SystemUpdateOperation::STATUS_FAILED,
            40,
            [['name' => 'migrate', 'status' => 'failed', 'detail' => 'forced failure']],
            'Migration failed; backup retained.'
        );

        $status = $this->updates->getStatus();
        self::assertSame($operationId, $status['operation_id']);
        self::assertSame(SystemUpdateOperation::STATUS_FAILED, $status['status']);
        self::assertSame('Migration failed; backup retained.', $status['message']);

        // A failed operation is terminal: not claimable and not re-writable.
        self::assertNull($this->updates->claimPendingOperation());
        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_CONFLICT);
        $this->updates->recordManagerStatus($operationId, SystemUpdateOperation::STATUS_UPDATE_RUNNING, 50);
    }
}
