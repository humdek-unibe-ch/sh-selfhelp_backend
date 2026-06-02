<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Tests\Support\NarrowsJson;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy base for the existing controller integration tests. It now logs in
 * as the seeded QA personas instead of a developer's personal credentials.
 *
 * New controller tests should extend {@see \App\Tests\Support\QaWebTestCase}
 * (richer envelope/permission helpers, fail-fast baseline check). This class
 * deliberately keeps the polite "throw on missing admin" behaviour so
 * {@see \App\Tests\Controller\Api\V1\Admin\Plugin\ManagedModeInstallTest}
 * can skip in dev environments without a seeded baseline (plan §26).
 */
class BaseControllerTest extends WebTestCase
{
    use NarrowsJson;

    protected JsonSchemaValidationService $jsonSchemaValidationService;
    protected KernelBrowser $client;

    private ?string $adminAccessToken = null;
    private ?string $userAccessToken = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $service = self::getContainer()->get(JsonSchemaValidationService::class);
        self::assertInstanceOf(JsonSchemaValidationService::class, $service);
        $this->jsonSchemaValidationService = $service;
    }

    protected function getAdminAccessToken(): string
    {
        if ($this->adminAccessToken) {
            return $this->adminAccessToken;
        }

        // Seeded QA admin persona (see QaBaselineFixture). No developer creds.
        $this->client->request(
            'POST',
            '/cms-api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                'email' => QaBaselineFixture::QA_ADMIN_EMAIL,
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Admin login failed. Run: composer test:reset-db');
        $this->adminAccessToken = $this->extractAccessToken($response);
        return $this->adminAccessToken;
    }

    protected function getUserAccessToken(): string
    {
        if ($this->userAccessToken) {
            return $this->userAccessToken;
        }

        // Seeded QA regular-user persona (non-admin). No developer creds.
        $this->client->request(
            'POST',
            '/cms-api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                'email' => QaBaselineFixture::QA_USER_EMAIL,
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'User login failed. Run: composer test:reset-db');
        $this->userAccessToken = $this->extractAccessToken($response);
        return $this->userAccessToken;
    }

    /**
     * Decode the current client response body as a JSON object (stdClass),
     * matching the legacy `json_decode($response->getContent())` usage.
     */
    protected function decodeObject(): \stdClass
    {
        return $this->asObject(json_decode((string) $this->client->getResponse()->getContent()));
    }

    /**
     * Decode the current client response body as a string-keyed array,
     * matching the legacy `json_decode($response->getContent(), true)` usage.
     *
     * @return array<string, mixed>
     */
    protected function decodeArray(): array
    {
        return $this->asArray(json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    private function extractAccessToken(Response $response): string
    {
        $data = $this->asArray(json_decode((string) $response->getContent(), true));
        $tokenData = $this->asArray($data['data'] ?? null, 'Login response missing "data"');
        $this->assertArrayHasKey('access_token', $tokenData);

        return $this->asString($tokenData['access_token'], 'access_token must be a string');
    }

    /**
     * @group smoke
     */
    public function testSmokeTest(): void
    {
        $this->assertSame(
            'test',
            $this->client->getKernel()->getEnvironment(),
            'Controller tests must run in the test environment.'
        );
    }
}
