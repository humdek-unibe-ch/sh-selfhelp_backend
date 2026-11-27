<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\RateLimit\PluginRateLimitedException;
use App\Plugin\RateLimit\PluginRateLimiterInterface;
use App\Tests\Support\Plugin\QaUsersPluginFixture;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * C15: a plugin declares a rate limit in its manifest and the host enforces it. The QA users plugin
 * ({@see QaUsersPluginFixture}) limits `qa_burst` to 2 per minute (sliding window) and `qa_slow` to 1 per hour
 * (fixed window). The counters live in the application cache, which the database rollback does not reach, so every
 * test counts under keys of its own that no other run has used.
 *
 * Through the QA route (`config/routes/test/plugin_users_routes.yaml`) the third call of a key is a real
 * 429 `rate_limited` answer in the API envelope with `meta.retry_after` and the `Retry-After` header.
 */
#[PhpUnitGroup('security')]
final class PluginRateLimiterTest extends QaWebTestCase
{
    private const ROUTE = '/cms-api/v1/qa-plugin/rate-limited';

    private PluginRateLimiterInterface $limiter;
    private EntityManagerInterface $em;
    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        // One container for the test and the requests, so the plugin installed here is the one they read.
        $this->client->disableReboot();
        $this->limiter = $this->service(PluginRateLimiterInterface::class);
        $this->em = $this->service(EntityManagerInterface::class);
        $this->plugin = QaUsersPluginFixture::install($this->em, $this->service(PluginCacheInvalidator::class));
    }

    protected function tearDown(): void
    {
        // The cache pool outlives the database rollback: the next test must not find the QA plugin.
        QaUsersPluginFixture::forget($this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // == the limiter ========================================================================

    public function testTheThirdCallOfALimitOfTwoPerMinuteIsRefused(): void
    {
        $key = $this->uniqueKey();

        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);

        try {
            $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);
            self::fail('The third call within the minute must be refused.');
        } catch (PluginRateLimitedException $e) {
            self::assertSame('rate_limited', $e->getMessage());
            self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $e->getCode());
            self::assertSame(QaUsersPluginFixture::PLUGIN_ID, $e->getPluginId());
            self::assertSame(QaUsersPluginFixture::LIMIT_NAME, $e->getLimitName());
            self::assertGreaterThanOrEqual(1, $e->getRetryAfterSeconds());
            self::assertLessThanOrEqual(60, $e->getRetryAfterSeconds(), 'the interval is one minute');
        }
    }

    public function testEveryKeyHasACounterOfItsOwn(): void
    {
        $spent = $this->uniqueKey();
        $fresh = $this->uniqueKey();
        $this->spend($spent, 2);

        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $fresh);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $fresh);

        $this->expectException(PluginRateLimitedException::class);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $spent);
    }

    public function testEveryLimitOfAPluginHasACounterOfItsOwn(): void
    {
        $key = $this->uniqueKey();
        $this->spend($key, 2);

        // The same key under the other limit is untouched: one call of an hour is still free.
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_SLOW, $key);
        $this->expectException(PluginRateLimitedException::class);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_SLOW, $key);
    }

    public function testACallMayCostSeveralTokens(): void
    {
        $key = $this->uniqueKey();

        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key, 2);

        $this->expectException(PluginRateLimitedException::class);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);
    }

    public function testAnUnknownNameIsAProgrammingError(): void
    {
        $key = $this->uniqueKey();

        try {
            $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, 'qa_no_such_limit', $key);
            self::fail('A limit the manifest does not declare must throw.');
        } catch (\InvalidArgumentException $e) {
            // A programming error, not a refusal: `PluginRateLimitedException` is not an InvalidArgumentException,
            // so a refusal would have escaped this catch and failed the test.
            self::assertStringContainsString('qa_no_such_limit', $e->getMessage());
        }
    }

    public function testAPluginThatIsNotEnabledHasNoLimits(): void
    {
        $key = $this->uniqueKey();

        try {
            $this->limiter->consume('qa-no-such-plugin', QaUsersPluginFixture::LIMIT_NAME, $key);
            self::fail('An unknown plugin must throw.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        QaUsersPluginFixture::setEnabled($this->plugin, false, $this->em, $this->service(PluginCacheInvalidator::class));
        $this->expectException(\InvalidArgumentException::class);
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);
    }

    public function testAnUnusableKeyOrCostIsAProgrammingError(): void
    {
        $key = $this->uniqueKey();
        $calls = [
            'an empty key' => fn () => $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, ''),
            'no token' => fn () => $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key, 0),
            'a negative cost' => fn () => $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key, -1),
            'more tokens than the limit' => fn () => $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key, 3),
        ];

        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail("$what must throw");
            } catch (PluginRateLimitedException) {
                self::fail("$what is a programming error, not a spent limit");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // == the answer of a route ==============================================================

    public function testThroughARouteTheThirdCallAnswers429WithRetryAfter(): void
    {
        $key = $this->uniqueKey();

        foreach ([1, 2] as $call) {
            $this->client->request('GET', self::ROUTE, ['key' => $key]);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), "call $call is within the limit");
        }

        $this->client->request('GET', self::ROUTE, ['key' => $key]);
        $response = $this->client->getResponse();
        $envelope = $this->decode($response);

        $this->assertEnvelopeError($envelope, Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('rate_limited', $envelope['error']);
        self::assertSame('Too Many Requests', $envelope['message']);
        self::assertNull($envelope['data']);
        self::assertFalse($envelope['logged_in']);

        $meta = self::asArray($envelope['meta']);
        self::assertSame('v1', $meta['version']);
        self::assertArrayHasKey('timestamp', $meta);
        $retryAfter = self::asInt($meta['retry_after'], 'meta.retry_after is a whole number of seconds');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
        self::assertSame((string) $retryAfter, $response->headers->get('Retry-After'), 'the header says the same');
    }

    public function testAnotherKeyIsNotLimitedByTheSpentOne(): void
    {
        $spent = $this->uniqueKey();
        $this->spend($spent, 2);

        $this->client->request('GET', self::ROUTE, ['key' => $spent]);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', self::ROUTE, ['key' => $this->uniqueKey()]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testARouteOfAPluginThatIsNotEnabledIsABadRequestNotAnAnswerOfTheLimit(): void
    {
        QaUsersPluginFixture::setEnabled($this->plugin, false, $this->em, $this->service(PluginCacheInvalidator::class));

        $this->client->request('GET', self::ROUTE, ['key' => $this->uniqueKey()]);

        $envelope = $this->decode($this->client->getResponse());
        $this->assertEnvelopeError($envelope, Response::HTTP_BAD_REQUEST);
        self::assertNotSame('rate_limited', $envelope['error']);
    }

    // == helpers ============================================================================

    /** A key no earlier run has counted under: the counters are in the cache pool, which no rollback clears. */
    private function uniqueKey(): string
    {
        return 'qa-p12-' . bin2hex(random_bytes(8));
    }

    private function spend(string $key, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $key);
        }
    }
}
