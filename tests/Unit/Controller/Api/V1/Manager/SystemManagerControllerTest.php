<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api\V1\Manager;

use App\Controller\Api\V1\Manager\SystemManagerController;
use App\Exception\ServiceException;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\System\SystemUpdateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token-guard unit tests for the manager update-loop controller (CRITICAL 3).
 *
 * The manager routes are public (no permission rows), so the ONLY thing
 * standing between an anonymous caller and the update loop is the per-instance
 * manager bearer token. These tests prove the guard rejects every
 * unauthenticated path BEFORE any service work happens. The happy path and the
 * instance-scoping logic are covered at the service layer in
 * {@see \App\Tests\Unit\Service\System\SystemUpdateServiceManagerLoopTest}.
 */
final class SystemManagerControllerTest extends TestCase
{
    private function makeController(string $managerToken): SystemManagerController
    {
        // The service + formatter are never reached on the rejected paths; stubs
        // keep the test free of a database and schema validation.
        $service = $this->createStub(SystemUpdateService::class);
        $formatter = $this->createStub(ApiResponseFormatter::class);
        $validator = $this->createStub(JsonSchemaValidationService::class);

        return new SystemManagerController($service, $formatter, $validator, $managerToken);
    }

    private function request(?string $authorization): Request
    {
        $headers = [];
        if ($authorization !== null) {
            $headers['HTTP_AUTHORIZATION'] = $authorization;
        }

        return new Request([], [], [], [], [], $headers);
    }

    public function testRejectsWhenManagerLoopIsNotConfigured(): void
    {
        $controller = $this->makeController('');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_UNAUTHORIZED);

        $controller->getPending($this->request('Bearer anything'));
    }

    public function testRejectsMissingAuthorizationHeader(): void
    {
        $controller = $this->makeController('s3cret-token');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_UNAUTHORIZED);

        $controller->getPending($this->request(null));
    }

    public function testRejectsWrongBearerToken(): void
    {
        $controller = $this->makeController('s3cret-token');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_UNAUTHORIZED);

        $controller->getPending($this->request('Bearer not-the-token'));
    }

    public function testRejectsNonBearerScheme(): void
    {
        $controller = $this->makeController('s3cret-token');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_UNAUTHORIZED);

        $controller->getPending($this->request('Basic czNjcmV0LXRva2Vu'));
    }

    public function testStatusWriteBackAlsoRequiresValidToken(): void
    {
        $controller = $this->makeController('s3cret-token');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_UNAUTHORIZED);

        $controller->postStatus($this->request('Bearer wrong'), 'op-123');
    }
}
