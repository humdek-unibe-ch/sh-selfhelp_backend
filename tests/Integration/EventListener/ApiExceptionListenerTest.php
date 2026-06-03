<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Coverage for {@see ApiExceptionListener} — the listener that turns every
 * uncaught exception on a `/cms-api/` route into the canonical JSON error
 * envelope with the right HTTP status (plan Phase 7: "canonical exception
 * envelopes"). Driven through the real listener + real ApiResponseFormatter so
 * the envelope shape is the production one.
 */
final class ApiExceptionListenerTest extends QaKernelTestCase
{
    private ApiExceptionListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->service(ApiExceptionListener::class);
    }

    public function testServiceExceptionMapsToItsHttpStatus(): void
    {
        $response = $this->handle(new ServiceException('Resource gone', Response::HTTP_NOT_FOUND));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $envelope = $this->decode($response);
        self::assertSame(Response::HTTP_NOT_FOUND, $envelope['status']);
        self::assertSame('Resource gone', $envelope['error']);
    }

    public function testServiceExceptionWithInvalidCodeFallsBackTo500(): void
    {
        // code 0 is not a valid HTTP status -> must be coerced to 500.
        $response = $this->handle(new ServiceException('Broken', 0));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testRequestValidationExceptionMapsTo400WithValidationField(): void
    {
        $exception = new RequestValidationException(
            ['The property qa_field is required'],
            'requests/qa/example',
            ['qa_field' => null],
        );

        $response = $this->handle($exception);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $envelope = $this->decode($response);
        self::assertArrayHasKey('validation', $envelope, 'Validation errors must be exposed in their own field.');
        self::assertSame('requests/qa/example', $this->asArray($envelope['validation'])['schema'] ?? null);
    }

    public function testInvalidArgumentExceptionMapsTo400(): void
    {
        $response = $this->handle(new \InvalidArgumentException('Bad JSON'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('Bad JSON', $this->decode($response)['error']);
    }

    public function testHttpExceptionPreservesItsStatus(): void
    {
        $response = $this->handle(new NotFoundHttpException('Nope'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGenericExceptionMapsTo500(): void
    {
        $response = $this->handle(new \RuntimeException('Kaboom'));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    }

    public function testNonApiPathIsLeftUntouched(): void
    {
        $event = new ExceptionEvent(
            self::bootedKernel(),
            Request::create('/not-an-api-route'),
            HttpKernelInterface::MAIN_REQUEST,
            new ServiceException('ignored', Response::HTTP_NOT_FOUND),
        );

        $this->listener->onKernelException($event);

        self::assertNull($event->getResponse(), 'Non-API routes must not be wrapped by the API exception listener.');
    }

    private function handle(\Throwable $exception): Response
    {
        $event = new ExceptionEvent(
            self::bootedKernel(),
            Request::create('/cms-api/v1/admin/qa-listener-probe'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        $this->listener->onKernelException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response, 'API exceptions must produce a JSON envelope.');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return $this->asArray(json_decode((string) $response->getContent(), true));
    }
}
