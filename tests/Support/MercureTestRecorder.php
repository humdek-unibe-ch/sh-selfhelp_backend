<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * In-memory Mercure hub used in tests.
 *
 * The real {@see HubInterface} talks to a Mercure server over HTTP. In tests we
 * alias `Symfony\Component\Mercure\HubInterface` to this recorder (see
 * config/services_test.yaml) so that:
 *
 *   1. No test ever performs real outbound HTTP to a hub (anti-flakiness +
 *      the "no real outbound" rule, plan §9/§30).
 *   2. Tests can assert exactly which realtime updates the code under test
 *      published, by topic, WITHOUT polling (plan §9: Mercure recorder, never
 *      polling).
 *
 * Every published {@see Update} is captured. Assertions are expressed in terms
 * of topics so a test states intent ("an ACL-version update was published")
 * rather than coupling to payload internals.
 */
final class MercureTestRecorder implements HubInterface
{
    /**
     * The environment switch of the browser (e2e) stack: with it set to `1` every update is also
     * sent to the real test hub, so the open pages of that stack really receive it. Unit and
     * integration runs never set it and stay in memory.
     */
    public const FORWARD_ENV = 'SELFHELP_E2E_REAL_HUB';

    /** @var list<Update> */
    private array $updates = [];

    public function __construct(private readonly ?HubInterface $forwardTo = null)
    {
    }

    /**
     * Prefer getenv, then $_ENV / $_SERVER — `php -S` on Windows often leaves
     * process env out of getenv() unless the harness router putenv()'s it.
     *
     * The e2e stack also drops `var/e2e-real-hub.flag` (see
     * `scripts/e2e-stack.mjs`): on Windows the custom env var frequently never
     * reaches this class even after the router putenv's it, so the flag is the
     * reliable switch for browser golden runs. PHPUnit never creates the flag.
     */
    private function shouldForwardToRealHub(): bool
    {
        $raw = getenv(self::FORWARD_ENV);
        if ($raw === false) {
            $raw = $_ENV[self::FORWARD_ENV] ?? $_SERVER[self::FORWARD_ENV] ?? null;
        }
        if ($raw === '0' || $raw === 0 || $raw === false || $raw === 'false') {
            return false;
        }
        if ($raw === '1' || $raw === 1 || $raw === true || $raw === 'true') {
            return true;
        }

        return is_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'e2e-real-hub.flag');
    }

    public function getPublicUrl(): string
    {
        return 'https://mercure.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        if ($this->forwardTo !== null && $this->shouldForwardToRealHub()) {
            return $this->forwardTo->publish($update);
        }

        // The real hub returns the published event id; a deterministic stub is
        // enough for tests and keeps callers (which may log the id) happy.
        return 'qa-mercure-' . count($this->updates);
    }

    /**
     * Forget everything recorded so far. Call in setUp when a single kernel
     * boot is reused across logical phases.
     */
    public function reset(): void
    {
        $this->updates = [];
    }

    /**
     * @return list<Update>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    public function count(): int
    {
        return count($this->updates);
    }

    /**
     * Every topic across every published update (flattened).
     *
     * @return list<string>
     */
    public function getPublishedTopics(): array
    {
        $topics = [];
        foreach ($this->updates as $update) {
            foreach ($update->getTopics() as $topic) {
                if (is_string($topic)) {
                    $topics[] = $topic;
                }
            }
        }

        return $topics;
    }

    public function assertNothingPublished(string $message = ''): void
    {
        Assert::assertSame(
            [],
            $this->getPublishedTopics(),
            $message !== '' ? $message : 'Expected no Mercure updates to be published, but some were.'
        );
    }

    /**
     * Assert at least one published update carries a topic containing $needle.
     */
    public function assertTopicPublished(string $needle, string $message = ''): void
    {
        foreach ($this->getPublishedTopics() as $topic) {
            if (str_contains($topic, $needle)) {
                Assert::assertStringContainsString($needle, $topic);

                return;
            }
        }

        Assert::fail(
            $message !== ''
                ? $message
                : sprintf('Expected a Mercure update on a topic containing "%s". Published topics: %s', $needle, implode(', ', $this->getPublishedTopics()) ?: '(none)')
        );
    }
}
