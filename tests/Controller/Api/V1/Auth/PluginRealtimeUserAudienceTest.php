<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Auth;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Plugin\Plugin;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Realtime\PluginRealtimePublisherInterface;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\Plugin\QaPlatformPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * C18: realtime to one user.
 *
 * A plugin publishes with `audience: 'user'` and `topicParams: ['userId' => <id>]`. The update goes out on a
 * private topic whose address names the user (`…/plugin/<pluginId>/<topicKey>/userId/<id>`), and
 * `GET /cms-api/v1/auth/events` puts exactly that topic, for the signed-in user's own id, into the subscriber
 * token. Another user's token, a guest's token and the token of a user without a permission the topic requires
 * do not contain it, and a call to `publish()` without a positive `userId` is refused instead of sent to
 * everybody.
 *
 * The QA platform plugin ({@see QaPlatformPluginFixture}) declares the topics `qa-progress` and
 * `qa-restricted` (needs `admin.action.read`). The hub is the in-memory {@see MercureTestRecorder}.
 *
 * @phpstan-type PublishOptions array{
 *   audience?: 'permission'|'broadcast'|'admins'|'user',
 *   topicParams?: array<string, string|int>,
 *   event?: string,
 *   private?: bool,
 * }
 */
final class PluginRealtimeUserAudienceTest extends QaWebTestCase
{
    private const EVENTS = '/cms-api/v1/auth/events';

    private EntityManagerInterface $em;
    private PluginRealtimePublisherInterface $publisher;
    private MercureTestRecorder $hub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->service(EntityManagerInterface::class);
        $publisher = self::getContainer()->get('test.plugin_realtime_publisher');
        self::assertInstanceOf(PluginRealtimePublisherInterface::class, $publisher);
        $this->publisher = $publisher;
        $hub = $this->service(MercureTestRecorder::class);
        self::assertInstanceOf(MercureTestRecorder::class, $hub);
        $this->hub = $hub;
        $this->hub->reset();
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaPlatformPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- the subscriber token ---------------------------------------------------------------

    public function testTheTokenOfAUserHoldsTheirOwnTopicAndNoOtherUsers(): void
    {
        $this->installPlugin();
        $mine = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $theirs = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaUser()));

        $claim = $this->subscribeClaim($data);
        self::assertContains($this->topic(QaPlatformPluginFixture::TOPIC, $mine), $claim);
        self::assertNotContains($this->topic(QaPlatformPluginFixture::TOPIC, $theirs), $claim, 'The topic of another user is never granted.');
        self::assertContains($this->topic(QaPlatformPluginFixture::TOPIC, $mine), $this->strings($data['pluginTopics'] ?? null), 'The answer lists the topic next to the token.');
        foreach ($claim as $granted) {
            self::assertStringNotContainsString('/userId/' . $theirs, $granted);
        }
    }

    public function testEachUserGetsTheirOwnTopic(): void
    {
        $this->installPlugin();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $adminId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $admin = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaAdmin()));

        $claim = $this->subscribeClaim($admin);
        self::assertContains($this->topic(QaPlatformPluginFixture::TOPIC, $adminId), $claim);
        self::assertNotContains($this->topic(QaPlatformPluginFixture::TOPIC, $userId), $claim);
    }

    public function testAGuestGetsNoTopicOfAUser(): void
    {
        $this->installPlugin();

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS));

        self::assertSame([], $data['pluginTopics'] ?? null);
        foreach ($this->subscribeClaim($data) as $granted) {
            self::assertStringNotContainsString('/plugin/' . QaPlatformPluginFixture::PLUGIN_ID . '/', $granted);
        }
    }

    public function testNoTopicIsGrantedWhileThePluginIsDisabled(): void
    {
        $this->installPlugin(enabled: false);

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaUser()));

        self::assertSame([], $data['pluginTopics'] ?? null);
    }

    public function testATopicThatRequiresAPermissionGoesOnlyToUsersWhoHoldIt(): void
    {
        $this->installPlugin();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $adminId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        $user = $this->subscribeClaim($this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaUser())));
        $admin = $this->subscribeClaim($this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaAdmin())));

        self::assertNotContains($this->topic(QaPlatformPluginFixture::RESTRICTED_TOPIC, $userId), $user);
        self::assertContains($this->topic(QaPlatformPluginFixture::RESTRICTED_TOPIC, $adminId), $admin);
        self::assertNotContains($this->topic(QaPlatformPluginFixture::RESTRICTED_TOPIC, $userId), $admin);
    }

    // -- publishing to one user ---------------------------------------------------------------

    public function testAnUpdateForAUserIsPrivateAndGoesToTheTopicTheTokenGrants(): void
    {
        $this->installPlugin();
        $userId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);

        $this->publisher->publish(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::TOPIC,
            ['percent' => 50],
            ['audience' => 'user', 'topicParams' => ['userId' => $userId]],
        );

        self::assertCount(1, $this->hub->getUpdates());
        $update = $this->hub->getUpdates()[0];
        self::assertSame([$this->topic(QaPlatformPluginFixture::TOPIC, $userId)], $update->getTopics());
        self::assertTrue($update->isPrivate(), 'An update for one user is never public.');
        self::assertSame('{"percent":50}', $update->getData());
        self::assertSame(QaPlatformPluginFixture::TOPIC, $update->getType());

        // The very topic the update went to is the one the token of that user holds.
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::EVENTS, null, $this->loginAsQaUser()));
        self::assertContains($update->getTopics()[0], $this->subscribeClaim($data));
    }

    public function testAnUpdateForAUserStaysPrivateWhenThePluginAsksForAPublicOne(): void
    {
        $this->publisher->publish(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::TOPIC,
            ['percent' => 1],
            ['audience' => 'user', 'topicParams' => ['userId' => 7], 'private' => false],
        );

        self::assertCount(1, $this->hub->getUpdates());
        self::assertTrue($this->hub->getUpdates()[0]->isPrivate());
    }

    public function testAUserIdGivenAsDigitsIsAccepted(): void
    {
        $this->publisher->publish(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::TOPIC,
            [],
            ['audience' => 'user', 'topicParams' => ['userId' => '12']],
        );

        self::assertSame([$this->topic(QaPlatformPluginFixture::TOPIC, 12)], $this->hub->getUpdates()[0]->getTopics());
    }

    /**
     * @return iterable<string, array{PublishOptions}>
     */
    public static function callsWithoutAUserId(): iterable
    {
        yield 'no topic parameters' => [['audience' => 'user']];
        yield 'empty topic parameters' => [['audience' => 'user', 'topicParams' => []]];
        yield 'zero' => [['audience' => 'user', 'topicParams' => ['userId' => 0]]];
        yield 'negative' => [['audience' => 'user', 'topicParams' => ['userId' => -3]]];
        yield 'text' => [['audience' => 'user', 'topicParams' => ['userId' => 'me']]];
        yield 'empty text' => [['audience' => 'user', 'topicParams' => ['userId' => '']]];
        yield 'another parameter name' => [['audience' => 'user', 'topicParams' => ['user' => 5]]];
        yield 'a second parameter' => [['audience' => 'user', 'topicParams' => ['userId' => 5, 'survey' => 9]]];
    }

    /**
     * @param PublishOptions $options
     */
    #[DataProvider('callsWithoutAUserId')]
    public function testPublishingToAUserWithoutAPositiveUserIdThrowsAndSendsNothing(array $options): void
    {
        try {
            $this->publisher->publish(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::TOPIC, ['percent' => 1], $options);
            self::fail('A call for the audience "user" without a positive userId must throw.');
        } catch (\InvalidArgumentException) {
            $this->hub->assertNothingPublished('A refused call must not reach any topic.');
        }
    }

    public function testTheUserIdParameterBelongsToTheUserAudienceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Without the audience a plugin could address the topic that a user is granted by their id.
        $this->publisher->publish(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::TOPIC,
            [],
            ['topicParams' => ['userId' => 5]],
        );
    }

    public function testTheOtherAudiencesAreUnchanged(): void
    {
        $this->publisher->publish(
            QaPlatformPluginFixture::PLUGIN_ID,
            QaPlatformPluginFixture::TOPIC,
            ['percent' => 1],
            ['topicParams' => ['survey' => 5]],
        );

        $update = $this->hub->getUpdates()[0];
        self::assertSame(
            [$this->publisher->resolveTopicIri(QaPlatformPluginFixture::PLUGIN_ID, QaPlatformPluginFixture::TOPIC, ['survey' => 5])],
            $update->getTopics(),
        );
        self::assertStringEndsWith('/plugin/' . QaPlatformPluginFixture::PLUGIN_ID . '/qa-progress/survey/5', $update->getTopics()[0]);
        self::assertTrue($update->isPrivate(), 'The default is still private.');
    }

    // -- helpers ----------------------------------------------------------------------------------

    private function installPlugin(bool $enabled = true): Plugin
    {
        return QaPlatformPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
            $enabled,
        );
    }

    /** The address of the topic `$key` of the QA plugin for the user `$userId`. */
    private function topic(string $key, int $userId): string
    {
        return $this->publisher->resolveTopicIri(QaPlatformPluginFixture::PLUGIN_ID, $key, ['userId' => $userId]);
    }

    /**
     * The `mercure.subscribe` claim of the subscriber JWT in an `auth/events` answer.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function subscribeClaim(array $data): array
    {
        $jwt = $data['token'] ?? null;
        self::assertIsString($jwt);
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'A JWT has three parts.');
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        $mercure = $payload['mercure'] ?? null;
        self::assertIsArray($mercure);

        return $this->strings($mercure['subscribe'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_filter($value, 'is_string'));
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
