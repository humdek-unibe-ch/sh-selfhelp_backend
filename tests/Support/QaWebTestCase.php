<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\Test\QaBaselineFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for HTTP/controller tests. Provides:
 *   - real JWT login as the seeded QA personas (no hardcoded credentials);
 *   - JSON request helpers;
 *   - response-envelope assertions (status + shape, plan §13);
 *   - a fail-fast QA-baseline check in setUp (plan §10 / §32).
 *
 * Replaces the old BaseControllerTest pattern that hardcoded a developer's
 * personal email/password.
 */
abstract class QaWebTestCase extends WebTestCase
{
    use InteractsWithQaBaseline;
    use NarrowsJson;

    protected KernelBrowser $client;

    /** @var array<string, string> email => access token, cached per test. */
    private array $tokenCache = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertQaBaselineLoaded($em);
    }

    // -- Login helpers ------------------------------------------------------

    protected function loginAs(string $email, string $password = QaBaselineFixture::QA_PASSWORD): string
    {
        if (isset($this->tokenCache[$email])) {
            return $this->tokenCache[$email];
        }

        $this->client->request(
            'POST',
            '/cms-api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR)
        );

        $response = $this->client->getResponse();
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            sprintf('Login failed for %s: %s', $email, (string) $response->getContent())
        );

        $data = $this->decode($response);
        $payload = $data['data'] ?? null;
        self::assertIsArray($payload, 'Login response missing data');
        self::assertArrayHasKey('access_token', $payload, 'Login response missing data.access_token');
        $accessToken = $payload['access_token'];
        self::assertIsString($accessToken, 'access_token must be a string');

        return $this->tokenCache[$email] = $accessToken;
    }

    protected function loginAsQaAdmin(): string
    {
        return $this->loginAs(QaBaselineFixture::QA_ADMIN_EMAIL);
    }

    protected function loginAsQaEditor(): string
    {
        return $this->loginAs(QaBaselineFixture::QA_EDITOR_EMAIL);
    }

    protected function loginAsQaUser(): string
    {
        return $this->loginAs(QaBaselineFixture::QA_USER_EMAIL);
    }

    protected function loginAsQaGuest(): string
    {
        return $this->loginAs(QaBaselineFixture::QA_GUEST_EMAIL);
    }

    // -- Container helpers --------------------------------------------------

    /**
     * Fetch a public service from the test container with a precise type.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        /** @var T $service */
        $service = self::getContainer()->get($id);

        return $service;
    }

    // -- Request helpers ----------------------------------------------------

    /**
     * @param array<string, string> $extra additional server headers
     * @return array<string, string>
     */
    protected function authHeaders(string $token, array $extra = []): array
    {
        return [
            'HTTP_Authorization' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ] + $extra;
    }

    /**
     * Perform a JSON request and return the decoded envelope.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    protected function jsonRequest(
        string $method,
        string $uri,
        ?array $body = null,
        ?string $token = null,
        array $extraHeaders = [],
    ): array {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_Authorization'] = 'Bearer ' . $token;
        }
        $headers = array_merge($headers, $extraHeaders);

        $this->client->request(
            $method,
            $uri,
            [],
            [],
            $headers,
            $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null
        );

        return $this->decode($this->client->getResponse());
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'Response body was not a JSON object: ' . $content);

        // Rebuild with string keys so the type is a precise array<string, mixed>
        // (json_decode of a JSON object always yields string keys at runtime).
        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    // -- Envelope assertions (plan §13) -------------------------------------

    /**
     * Assert the standard success envelope and return its `data` payload.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    protected function assertEnvelopeSuccess(array $envelope, int $expectedStatus = Response::HTTP_OK): array
    {
        self::assertArrayHasKey('status', $envelope, 'Envelope missing "status"');
        self::assertSame(
            $expectedStatus,
            $envelope['status'],
            'Unexpected envelope status. Envelope: ' . json_encode(
                ['status' => $envelope['status'] ?? null, 'message' => $envelope['message'] ?? null, 'error' => $envelope['error'] ?? null],
            ),
        );
        self::assertArrayHasKey('error', $envelope, 'Envelope missing "error"');
        self::assertNull($envelope['error'], 'Success envelope must have null error');
        self::assertArrayHasKey('logged_in', $envelope, 'Envelope missing "logged_in"');
        self::assertArrayHasKey('meta', $envelope, 'Envelope missing "meta"');
        self::assertArrayHasKey('data', $envelope, 'Envelope missing "data"');

        $payload = $envelope['data'];
        if (!is_array($payload)) {
            return [];
        }

        $result = [];
        foreach ($payload as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    protected function assertEnvelopeError(array $envelope, int $expectedStatus): void
    {
        self::assertArrayHasKey('status', $envelope, 'Envelope missing "status"');
        self::assertSame($expectedStatus, $envelope['status'], 'Unexpected error envelope status');
        self::assertArrayHasKey('error', $envelope, 'Error envelope missing "error"');
    }

    /** @param array<string, mixed> $e */
    protected function assertEnvelope400(array $e): void
    {
        $this->assertEnvelopeError($e, Response::HTTP_BAD_REQUEST);
    }

    /** @param array<string, mixed> $e */
    protected function assertEnvelope401(array $e): void
    {
        $this->assertEnvelopeError($e, Response::HTTP_UNAUTHORIZED);
    }

    /** @param array<string, mixed> $e */
    protected function assertEnvelope403(array $e): void
    {
        $this->assertEnvelopeError($e, Response::HTTP_FORBIDDEN);
    }

    /** @param array<string, mixed> $e */
    protected function assertEnvelope404(array $e): void
    {
        $this->assertEnvelopeError($e, Response::HTTP_NOT_FOUND);
    }
}
