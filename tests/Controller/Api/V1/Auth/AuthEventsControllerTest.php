<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Auth\AuthEventsController}:
 * the Mercure subscriber-bootstrap endpoint `GET /auth/events`.
 *
 * Asserts the 401 anonymous guard, the bearer-transport payload (hub URL +
 * per-user ACL/impersonation topics + a short-lived subscriber JWT, validated
 * against `responses/auth/events`), and the cookie-transport variant (token
 * moves into the `mercureAuthorization` cookie and is omitted from the body).
 */
final class AuthEventsControllerTest extends QaWebTestCase
{
    private const URI = '/cms-api/v1/auth/events';

    private JsonSchemaValidationService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = $this->service(JsonSchemaValidationService::class);
    }

    public function testEventsRequiresAuthentication(): void
    {
        $envelope = $this->jsonRequest('GET', self::URI);
        $this->assertEnvelope401($envelope);
    }

    public function testEventsReturnsBearerSubscriberToken(): void
    {
        $token = $this->loginAsQaUser();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $envelope = $this->jsonRequest('GET', self::URI, null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);

        $this->assertResponseSchema('responses/auth/events');

        self::assertIsString($data['hubUrl'] ?? null);
        self::assertNotSame('', $data['hubUrl']);
        self::assertIsString($data['topic'] ?? null);
        self::assertStringContainsString("/users/{$userId}/acl", $data['topic']);
        self::assertStringContainsString("/users/{$userId}/impersonation", $this->coerceString($data['impersonationTopic'] ?? ''));
        self::assertIsArray($data['pluginTopics'] ?? null);
        self::assertIsString($data['token'] ?? null);
        self::assertNotSame('', $data['token'], 'Bearer transport must return a non-empty subscriber JWT.');
        self::assertGreaterThan(0, $this->coerceInt($data['expiresIn'] ?? 0));
    }

    public function testEventsCookieTransportMovesTokenIntoCookie(): void
    {
        $token = $this->loginAsQaUser();

        $this->client->request(
            'GET',
            self::URI . '?transport=cookie',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $envelope = $this->decode($response);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertNull($data['token'], 'Cookie transport must omit the token from the body.');

        $cookieNames = array_map(
            static fn ($c): string => $c->getName(),
            $response->headers->getCookies()
        );
        self::assertContains('mercureAuthorization', $cookieNames, 'Cookie transport must set the mercureAuthorization cookie.');
    }

    // -- helpers ------------------------------------------------------------

    private function assertResponseSchema(string $schemaName): void
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent());
        $errors = $this->schema->validate($this->asObject($decoded), $schemaName);
        self::assertSame([], $errors, "Response failed schema {$schemaName}:\n" . implode("\n", $errors));
    }

    private function userId(string $email): int
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
