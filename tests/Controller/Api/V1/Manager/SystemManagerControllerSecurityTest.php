<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Manager;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * HTTP security coverage for the manager update-loop routes
 * ({@see \App\Controller\Api\V1\Manager\SystemManagerController}).
 *
 * The token-guard branches are unit-tested in
 * {@see \App\Tests\Unit\Controller\Api\V1\Manager\SystemManagerControllerTest};
 * this test instead exercises the routes end to end through the DB-backed
 * router + the API security pipeline to prove the contract that matters for
 * security:
 *   - the routes are registered and reachable WITHOUT a JWT (they are public,
 *     like `health`) — a missing token yields a 401 from the in-controller guard,
 *     NOT a 404 (route missing) or a JWT/ACL 401;
 *   - a valid admin JWT does NOT unlock them (they are gated by the per-instance
 *     manager token, never by ACL), so even an authorised admin is denied;
 *   - both the claim (GET) and the status write-back (POST) enforce the guard
 *     before any service work.
 *
 * The manager loop is disabled in the test environment (no
 * `SELFHELP_MANAGER_TOKEN`), so every call is denied; the assertions hold
 * whether the token is unset or configured, because neither an arbitrary bearer
 * nor an admin JWT can ever equal the per-instance manager token.
 */
#[Group('security')]
final class SystemManagerControllerSecurityTest extends QaWebTestCase
{
    private const PENDING = '/cms-api/v1/manager/system/update/pending';
    private const STATUS = '/cms-api/v1/manager/system/update/op_test123/status';

    public function testPendingIsAPublicRouteButDeniedWithoutAManagerToken(): void
    {
        // No Authorization header at all: reaches the controller (public route)
        // and is denied by the manager-token guard with 401 — not a 404.
        $this->assertEnvelope401($this->jsonRequest('GET', self::PENDING));
    }

    public function testPendingRejectsAnArbitraryBearerToken(): void
    {
        $this->assertEnvelope401($this->jsonRequest('GET', self::PENDING, null, 'not-the-manager-token'));
    }

    public function testPendingIsNotUnlockedByAValidAdminJwt(): void
    {
        // An authorised admin JWT must NOT unlock the manager loop: these routes
        // are gated by the per-instance manager token, never by ACL.
        $this->assertEnvelope401($this->jsonRequest('GET', self::PENDING, null, $this->loginAsQaAdmin()));
    }

    public function testStatusWriteBackIsAPublicRouteButDeniedWithoutAManagerToken(): void
    {
        $this->assertEnvelope401($this->jsonRequest('POST', self::STATUS, [
            'status' => 'update_running',
            'progress_percent' => 50,
        ]));
    }

    public function testStatusWriteBackRejectsAnArbitraryBearerToken(): void
    {
        $this->assertEnvelope401($this->jsonRequest('POST', self::STATUS, [
            'status' => 'update_running',
            'progress_percent' => 50,
        ], 'not-the-manager-token'));
    }

    public function testStatusWriteBackIsNotUnlockedByAValidAdminJwt(): void
    {
        $this->assertEnvelope401($this->jsonRequest('POST', self::STATUS, [
            'status' => 'update_running',
            'progress_percent' => 50,
        ], $this->loginAsQaAdmin()));
    }
}
