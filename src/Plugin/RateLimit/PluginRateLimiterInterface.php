<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\RateLimit;

/**
 * Rate limits a plugin declares and the host enforces (contract C15).
 *
 * The plugin declares a limit in its manifest (`rateLimits`: `name`, `policy`,
 * `limit`, `interval`) and calls {@see consume()} where the work starts, for
 * example before it asks a paid model for an answer. The host counts per key in
 * the application cache, like it counts failed logins.
 *
 * Choose the key so that it names who is being limited: `'user:' . $userId`,
 * an address, a conversation. One key is one counter; the same key under a
 * different limit name is a different counter.
 */
interface PluginRateLimiterInterface
{
    /**
     * Count `$tokens` against the limit `$name` of plugin `$pluginId` for `$key`.
     *
     * @param string $pluginId the plugin's manifest id; the plugin must be enabled
     * @param string $name the limit's name in the manifest's `rateLimits`
     * @param string $key who or what is limited (not empty)
     * @param int $tokens how much this call costs (1 or more, at most the limit)
     *
     * @throws PluginRateLimitedException when the limit is spent: the API answers 429 `rate_limited` with `meta.retry_after`
     * @throws \InvalidArgumentException when the plugin is not enabled or declares no limit of that name, or `$key`
     *         or `$tokens` is unusable (a programming error, never a client's fault)
     */
    public function consume(string $pluginId, string $name, string $key, int $tokens = 1): void;
}
