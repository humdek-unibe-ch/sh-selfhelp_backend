<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\RateLimit;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Enforces the rate limits of enabled plugins (contract C15).
 *
 * Each limit is built from the plugin's manifest declaration (`rateLimits`:
 * `policy` sliding or fixed window, `limit`, `interval`) with the Symfony rate
 * limiter, over the application cache pool and the default lock, exactly like
 * the limiters of the login endpoints (`config/packages/rate_limiter.yaml`).
 * Nothing is stored in the database.
 *
 * The factory is built from the declaration as it is now, so a plugin update
 * that changes a limit takes effect with the next call; the counter of a key
 * carries over to the new setting. The counter is per plugin, limit name and
 * key: another plugin, another name or another key never shares it.
 */
final class PluginRateLimiter implements PluginRateLimiterInterface
{
    private const POLICIES = ['sliding_window', 'fixed_window'];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    public function __construct(
        private readonly PluginRegistryService $registry,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function consume(string $pluginId, string $name, string $key, int $tokens = 1): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A rate limit needs a key that says who is limited.');
        }
        if ($tokens < 1) {
            throw new \InvalidArgumentException('A call costs 1 token or more.');
        }

        $limit = $this->factory($pluginId, $name)->create($key)->consume($tokens);
        if ($limit->isAccepted()) {
            return;
        }

        throw new PluginRateLimitedException($pluginId, $name, max(1, $limit->getRetryAfter()->getTimestamp() - time()));
    }

    private function factory(string $pluginId, string $name): RateLimiterFactory
    {
        $plugin = $this->registry->findByPluginId($pluginId);
        if ($plugin === null || !$plugin->isEnabled()) {
            throw new \InvalidArgumentException(sprintf('Plugin "%s" is not installed and enabled, so it has no rate limits.', $pluginId));
        }

        foreach ((new PluginManifest($plugin->getManifestJson()))->getRateLimits() as $declaration) {
            if (($declaration['name'] ?? null) !== $name) {
                continue;
            }

            $policy = $declaration['policy'] ?? null;
            $limit = $declaration['limit'] ?? null;
            $interval = $declaration['interval'] ?? null;
            if (!is_string($policy) || !in_array($policy, self::POLICIES, true) || !is_int($limit) || $limit < 1 || !is_string($interval)) {
                throw new \InvalidArgumentException(sprintf('The rate limit "%s" of plugin "%s" is declared with a policy, limit or interval the host does not accept.', $name, $pluginId));
            }

            $id = sprintf('plugin-%s-%s', $pluginId, $name);

            return $this->factories[sprintf('%s|%s|%d|%s', $id, $policy, $limit, $interval)] ??= new RateLimiterFactory(
                ['id' => $id, 'policy' => $policy, 'limit' => $limit, 'interval' => $interval],
                new CacheStorage($this->pool),
                $this->lockFactory,
            );
        }

        throw new \InvalidArgumentException(sprintf('Plugin "%s" declares no rate limit "%s".', $pluginId, $name));
    }
}
