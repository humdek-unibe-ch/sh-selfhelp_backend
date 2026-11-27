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
use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * F13: unexpected errors never leak exception text.
 */
final class ApiResponseFormatterUnexpectedTest extends TestCase
{
    use NarrowsJson;

    public function testUnexpectedRuntimeExceptionIsGeneric500(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Unhandled API exception',
            self::callback(static function (array $context): bool {
                return ($context['route'] ?? null) === 'form_submit_v1'
                    && isset($context['exception'])
                    && $context['exception'] instanceof \RuntimeException;
            })
        );

        $formatter = new ApiResponseFormatter(
            $this->createStub(Security::class),
            $this->createStub(JsonSchemaValidationService::class),
            $logger,
            false,
            true, // debug on — must still mask
        );

        $response = $formatter->formatThrowable(
            new \RuntimeException('SQLSTATE[HY000]: LEAKED_DB_DETAIL'),
            'form_submit_v1'
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $payload = self::asArray(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(ApiResponseFormatter::GENERIC_UNEXPECTED_ERROR, $payload['error'] ?? null);
        self::assertStringNotContainsString('LEAKED_DB_DETAIL', (string) $response->getContent());
    }

    public function testServiceExceptionKeepsMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $formatter = new ApiResponseFormatter(
            $this->createStub(Security::class),
            $this->createStub(JsonSchemaValidationService::class),
            $logger,
        );

        $response = $formatter->formatThrowable(
            new ServiceException('Field x is required', Response::HTTP_BAD_REQUEST)
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = self::asArray(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Field x is required', $payload['error'] ?? null);
    }

    public function testHttpException4xxKeepsMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $formatter = new ApiResponseFormatter(
            $this->createStub(Security::class),
            $this->createStub(JsonSchemaValidationService::class),
            $logger,
        );

        $response = $formatter->formatThrowable(new BadRequestHttpException('Bad payload shape'));
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = self::asArray(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Bad payload shape', $payload['error'] ?? null);
    }
}
