<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Tests\Controller\Api\V1;

use App\DataFixtures\Test\QaBaselineFixture;
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
    protected $jsonSchemaValidationService;
    protected $client;

    private $adminAccessToken;
    private $userAccessToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->jsonSchemaValidationService = self::getContainer()->get(JsonSchemaValidationService::class);
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
            json_encode([
                'email' => QaBaselineFixture::QA_ADMIN_EMAIL,
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'Admin login failed. Run: composer test:reset-db');
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->adminAccessToken = $data['data']['access_token'];
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
            json_encode([
                'email' => QaBaselineFixture::QA_USER_EMAIL,
                'password' => QaBaselineFixture::QA_PASSWORD,
            ])
        );
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), 'User login failed. Run: composer test:reset-db');
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->userAccessToken = $data['data']['access_token'];
        return $this->userAccessToken;
    }

    /**
     * @group smoke
     */
    public function testSmokeTest(): void
    {
        $this->assertTrue(true, 'Basic assertion to ensure tests can run.');
    }
}
