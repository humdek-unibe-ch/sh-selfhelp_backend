<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Service\Auth\LoginService;
use App\Service\Auth\ProfileService;
use App\Service\CMS\FormValidationService;
use App\Service\CMS\Frontend\PageService;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\ApiResponseFormatter;
use App\Tests\Support\QaWebTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * F13 per controller family: an unexpected error deep in the service a
 * public controller calls never reaches the visitor. The service is replaced
 * in the test container by a double that throws a `\RuntimeException` with a
 * recognisable text; the answer is `500` with a generic message, the text is
 * nowhere in the body, and the log holds it. A user-facing validation error
 * (`\InvalidArgumentException`) keeps its text.
 *
 * Families: form submit, page read, navigation, auth login, profile update.
 */
#[Group('security')]
final class ErrorMessageLeakTest extends QaWebTestCase
{
    private const LEAK = 'SQLSTATE[HY000] qa_leak secret_table';
    private const VALIDATION_TEXT = 'qa_visible validation text';

    private TestHandler $logRecords;

    /**
     * @return iterable<string, array{class-string, non-empty-string, string, array<string, mixed>|null, bool, string}>
     *         family => [service class, method, HTTP method + uri, body, signed in, generic message]
     */
    public static function families(): iterable
    {
        yield 'form submit' => [
            FormValidationService::class, 'authorize', 'POST /cms-api/v1/forms/submit',
            ['page_id' => 1, 'section_id' => 1, 'form_data' => ['qa_field' => 'qa value']],
            true, ApiResponseFormatter::GENERIC_UNEXPECTED_ERROR,
        ];
        yield 'page read' => [
            PageService::class, 'getPageByKeyword', 'GET /cms-api/v1/pages/by-keyword/qa-leak-probe',
            null, true, ApiResponseFormatter::GENERIC_UNEXPECTED_ERROR,
        ];
        yield 'navigation' => [
            NavigationMenuService::class, 'getPublicNavigationPayload', 'GET /cms-api/v1/navigation',
            null, true, ApiResponseFormatter::GENERIC_UNEXPECTED_ERROR,
        ];
        yield 'auth login' => [
            LoginService::class, 'validateUser', 'POST /cms-api/v1/auth/login',
            ['email' => 'qa.leak-probe@selfhelp.test', 'password' => 'QaLeakProbe!2026'],
            false, 'An error occurred during login.',
        ];
        yield 'profile update' => [
            ProfileService::class, 'updateName', 'PUT /cms-api/v1/auth/user/name',
            ['name' => 'qa_leak_name'],
            true, 'An error occurred while updating name.',
        ];
    }

    /**
     * @param class-string $serviceClass
     * @param non-empty-string $method
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('families')]
    public function testUnexpectedErrorAnswersGeneric500AndIsLogged(
        string $serviceClass,
        string $method,
        string $request,
        ?array $body,
        bool $signedIn,
        string $genericMessage,
    ): void {
        $token = $this->arrange($serviceClass, $method, new \RuntimeException(self::LEAK), $signedIn);

        [$httpMethod, $uri] = explode(' ', $request, 2);
        $envelope = $this->jsonRequest($httpMethod, $uri, $body, $token);
        $raw = (string) $this->client->getResponse()->getContent();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode(), $raw);
        self::assertSame($genericMessage, $envelope['error'] ?? null);
        self::assertStringNotContainsString('qa_leak', $raw, 'The exception text must never reach the response.');
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertTrue($this->logHolds(self::LEAK), 'The log must hold the unexpected exception.');
    }

    /**
     * @param class-string $serviceClass
     * @param non-empty-string $method
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('families')]
    public function testValidationErrorKeepsItsText(
        string $serviceClass,
        string $method,
        string $request,
        ?array $body,
        bool $signedIn,
        string $genericMessage,
    ): void {
        $token = $this->arrange($serviceClass, $method, new \InvalidArgumentException(self::VALIDATION_TEXT), $signedIn);

        [$httpMethod, $uri] = explode(' ', $request, 2);
        $envelope = $this->jsonRequest($httpMethod, $uri, $body, $token);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::VALIDATION_TEXT, $envelope['error'] ?? null);
        self::assertNotSame($genericMessage, $envelope['error']);
    }

    /**
     * Signs in when needed (one real request), then keeps the kernel so the
     * double and the log handler survive into the request under test.
     *
     * @param class-string $serviceClass
     * @param non-empty-string $method
     */
    private function arrange(string $serviceClass, string $method, \Throwable $error, bool $signedIn): ?string
    {
        $token = $signedIn ? $this->loginAsQaUser() : null;
        $this->client->disableReboot();

        $double = $this->getMockBuilder($serviceClass)->disableOriginalConstructor()->onlyMethods([$method])->getMock();
        // once(): the request really reached the double, not an earlier guard.
        $double->expects(self::once())->method($method)->willThrowException($error);
        self::getContainer()->set($serviceClass, $double);

        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $this->logRecords = new TestHandler();
        $logger->pushHandler($this->logRecords);

        return $token;
    }

    private function logHolds(string $text): bool
    {
        foreach ($this->logRecords->getRecords() as $record) {
            $exception = $record->context['exception'] ?? null;
            if ($exception instanceof \Throwable && str_contains($exception->getMessage(), $text)) {
                return true;
            }
            if (is_string($exception) && str_contains($exception, $text)) {
                return true;
            }
        }

        return false;
    }
}
