<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Core;

use App\Exception\ServiceException;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit coverage for {@see ApiResponseFormatter} — the single source of the
 * standard API envelope (plan P1 "ApiResponseFormatter / envelope shapes").
 *
 * Pure unit test: Security and the schema validator are deterministic doubles
 * and response-schema validation is left OFF (its default), so no schema files
 * or container are needed. Asserts the success / error / exception envelope
 * shapes the whole API depends on.
 */
final class ApiResponseFormatterTest extends TestCase
{
    private function formatter(bool $loggedInUser = false): ApiResponseFormatter
    {
        $user = $loggedInUser ? $this->createStub(UserInterface::class) : null;

        // Only getUser() is exercised by ApiResponseFormatter; Symfony's Security
        // helper is @final (soft) so it is doubled via a generated test stub
        // rather than a hand-written subclass (which PHPStan rejects as extending
        // an @final). A stub is the right tool here — getUser() is a passive
        // return-value source, not a behaviour we assert — and it avoids the
        // PHPUnit "mock object without configured expectations" notice.
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new ApiResponseFormatter(
            $security,
            $this->createStub(JsonSchemaValidationService::class),
            new NullLogger(),
            false, // validate_response_schema OFF (default)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testFormatSuccessProducesTheStandardEnvelope(): void
    {
        $response = $this->formatter()->formatSuccess(['foo' => 'bar']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $envelope = $this->decode($response);
        self::assertSame(Response::HTTP_OK, $envelope['status']);
        self::assertNull($envelope['error'], 'Success envelope must carry a null error.');
        self::assertArrayHasKey('logged_in', $envelope);
        self::assertFalse($envelope['logged_in'], 'No user + isLoggedIn=false => logged_in false.');
        self::assertArrayHasKey('meta', $envelope);
        self::assertIsArray($envelope['meta']);
        self::assertSame('v1', $envelope['meta']['version']);
        self::assertArrayHasKey('timestamp', $envelope['meta']);
        self::assertSame(['foo' => 'bar'], $envelope['data']);
        self::assertArrayNotHasKey('validation', $envelope, 'Success envelope has no validation key.');
    }

    public function testFormatSuccessReflectsAuthenticatedUserInLoggedIn(): void
    {
        $envelope = $this->decode($this->formatter(loggedInUser: true)->formatSuccess(null));
        self::assertTrue($envelope['logged_in'], 'A present Security user must surface as logged_in=true.');
    }

    public function testFormatSuccessHonoursExplicitStatusCode(): void
    {
        $response = $this->formatter()->formatSuccess(['id' => 1], null, Response::HTTP_CREATED, true);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $envelope = $this->decode($response);
        self::assertSame(Response::HTTP_CREATED, $envelope['status']);
        self::assertTrue($envelope['logged_in']);
    }

    public function testFormatErrorProducesAnErrorEnvelope(): void
    {
        $response = $this->formatter()->formatError('Boom', Response::HTTP_BAD_REQUEST, ['hint' => 'x']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $envelope = $this->decode($response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $envelope['status']);
        self::assertSame('Boom', $envelope['error']);
        self::assertSame(['hint' => 'x'], $envelope['data']);
        self::assertArrayNotHasKey('validation', $envelope, 'No validation key unless validation errors are passed.');
    }

    public function testFormatErrorIncludesValidationErrorsWhenProvided(): void
    {
        $envelope = $this->decode(
            $this->formatter()->formatError('Invalid', Response::HTTP_BAD_REQUEST, null, ["Field 'x': required"])
        );

        self::assertArrayHasKey('validation', $envelope);
        self::assertSame(["Field 'x': required"], $envelope['validation']);
    }

    public function testFormatExceptionMapsCodeMessageAndData(): void
    {
        $response = $this->formatter()->formatException(
            new ServiceException('Not allowed', Response::HTTP_FORBIDDEN, ['reason' => 'denied'])
        );
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $envelope = $this->decode($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $envelope['status']);
        self::assertSame('Not allowed', $envelope['error']);
        self::assertSame(['reason' => 'denied'], $envelope['data']);
    }
}
