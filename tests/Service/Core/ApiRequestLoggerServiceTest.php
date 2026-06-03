<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\Entity\ApiRequestLog;
use App\Service\Core\ApiRequestLoggerService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Behavioural coverage for {@see ApiRequestLoggerService} — specifically the
 * security-critical redaction (plan Phase 7: "sensitive request logging
 * redaction"). Asserted through the public {@see ApiRequestLoggerService::logRequest()}
 * entrypoint (never the private sanitisers) and the persisted {@see ApiRequestLog}.
 */
#[Group('security')]
final class ApiRequestLoggerServiceTest extends QaKernelTestCase
{
    private ApiRequestLoggerService $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->service(ApiRequestLoggerService::class);
    }

    public function testSensitiveBodyFieldsAreMaskedInTheLog(): void
    {
        $request = $this->jsonRequestWithSecrets();
        $response = new JsonResponse(['ok' => true]);

        $hash = $this->logger->startRequest($request);
        $log = $this->logger->logRequest($request, $response, $hash);

        self::assertInstanceOf(ApiRequestLog::class, $log);

        $params = json_decode((string) $log->getRequestParams(), true);
        self::assertIsArray($params);
        self::assertArrayHasKey('json', $params);
        $json = $this->asArray($params['json']);

        self::assertSame('qa-logger@selfhelp.test', $json['email'], 'Non-sensitive fields are preserved.');
        self::assertSame('********', $json['password'], 'Password must be masked.');
        self::assertSame('********', $json['token'], 'Token must be masked.');
        self::assertSame('********', $json['api_key'], 'API key must be masked.');
    }

    public function testSensitiveHeadersAreStrippedFromTheLog(): void
    {
        $request = $this->jsonRequestWithSecrets();
        $response = new JsonResponse(['ok' => true]);

        $hash = $this->logger->startRequest($request);
        $log = $this->logger->logRequest($request, $response, $hash);

        $headers = strtolower((string) $log->getRequestHeaders());

        self::assertStringNotContainsString('authorization', $headers, 'Authorization header must never be logged.');
        self::assertStringNotContainsString('x-api-key', $headers, 'X-Api-Key header must never be logged.');
        // The raw bearer secret value must never leak via any field.
        self::assertStringNotContainsString('super-secret-bearer', $headers);
    }

    public function testLogRecordsTheResponseStatusAndMethod(): void
    {
        $request = $this->jsonRequestWithSecrets();
        $response = new JsonResponse(['ok' => true], 201);

        $hash = $this->logger->startRequest($request);
        $log = $this->logger->logRequest($request, $response, $hash);

        self::assertSame(201, $log->getStatusCode());
        self::assertSame('POST', $log->getMethod());
        self::assertNotEmpty((string) $log->getResponseData(), 'Response body must be captured.');
    }

    private function jsonRequestWithSecrets(): Request
    {
        return Request::create(
            '/cms-api/v1/auth/login',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer super-secret-bearer',
                'HTTP_X_API_KEY' => 'super-secret-key',
            ],
            (string) json_encode([
                'email' => 'qa-logger@selfhelp.test',
                'password' => 'qa-secret-pass',
                'token' => 'qa-secret-token',
                'api_key' => 'qa-secret-apikey',
            ]),
        );
    }
}
