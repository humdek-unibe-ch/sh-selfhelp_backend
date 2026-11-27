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
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Sync\AclStampService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage for {@see \App\Controller\Api\V1\Auth\AuthEventsController}:
 * the Mercure subscriber-bootstrap endpoint `GET /auth/events`.
 *
 * Asserts that a guest is served with the public sync topic only (never 401), the bearer-transport payload (hub URL +
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

    public function testAGuestGetsThePublicSyncTopicOnly(): void
    {
        $envelope = $this->jsonRequest('GET', self::URI);
        $data = $this->assertEnvelopeSuccess($envelope);

        $this->assertResponseSchema('responses/auth/events');

        $public = $this->topics()->syncPublicTopic();
        self::assertSame([$public], $data['syncTopics'] ?? null);
        self::assertArrayHasKey('topic', $data);
        self::assertNull($data['topic']);
        self::assertArrayHasKey('impersonationTopic', $data);
        self::assertNull($data['impersonationTopic']);
        self::assertArrayHasKey('systemUpdateTopic', $data);
        self::assertNull($data['systemUpdateTopic']);
        self::assertSame([], $data['pluginTopics'] ?? null);
        self::assertIsString($data['token'] ?? null);
        self::assertSame([$public], $this->subscribeClaim($this->coerceString($data['token'])), 'A guest token authorises the public sync topic and nothing else.');
    }

    public function testASignedInCallerGetsTheirOwnGroupRoleAndPublicTopics(): void
    {
        $token = $this->loginAsQaUser();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $token));
        $this->assertResponseSchema('responses/auth/events');

        $memberships = $this->service(AclStampService::class)->memberships($userId);
        $expected = [$this->topics()->syncUserTopic($userId)];
        foreach (array_keys($memberships['groups']) as $groupId) {
            $expected[] = $this->topics()->syncGroupTopic($groupId);
        }
        foreach (array_keys($memberships['roles']) as $roleId) {
            $expected[] = $this->topics()->syncRoleTopic($roleId);
        }
        $expected[] = $this->topics()->syncPublicTopic();

        self::assertSame($expected, $data['syncTopics'] ?? null);
        self::assertStringContainsString("/sync/users/{$userId}", $this->coerceString($expected[0]));
        self::assertSame([], array_diff($expected, $this->subscribeClaim($this->coerceString($data['token'] ?? ''))), 'The token authorises every sync topic.');
        self::assertContains($this->coerceString($data['topic'] ?? ''), $this->subscribeClaim($this->coerceString($data['token'] ?? '')));
    }

    public function testNoSyncTopicOfAnotherUserIsListed(): void
    {
        $token = $this->loginAsQaUser();
        $adminId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $token));

        self::assertIsArray($data['syncTopics'] ?? null);
        self::assertNotContains($this->topics()->syncUserTopic($adminId), $data['syncTopics']);
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
        self::assertStringContainsString("/users/{$userId}/system-update", $this->coerceString($data['systemUpdateTopic'] ?? ''));
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

    private function topics(): MercureTopicResolver
    {
        return $this->service(MercureTopicResolver::class);
    }

    /**
     * The `mercure.subscribe` claim of a subscriber JWT.
     *
     * @return list<string>
     */
    private function subscribeClaim(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'A JWT has three parts.');
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        $mercure = $payload['mercure'] ?? null;
        self::assertIsArray($mercure);
        $subscribe = $mercure['subscribe'] ?? null;
        self::assertIsArray($subscribe);

        return array_values(array_map(static fn (mixed $topic): string => is_string($topic) ? $topic : '', $subscribe));
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
