<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Cache;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The cache categories that enabled plugins declare in their manifest
 * (`cacheCategories`, contract C2/C4).
 *
 * A category is named `<plugin id>.<name>` and has the lifetime the manifest
 * gives it. {@see \App\Service\Cache\Core\CacheService} asks this class for the
 * lifetime of a plugin category; the cache statistics and the admin cache
 * screen list the categories through it.
 *
 * Like {@see \App\Plugin\Resource\RegisteredResourceTypes} it reads the
 * database directly (the cache service cannot depend on a service that needs
 * the cache service) and keeps the answer for a few seconds per process.
 */
class PluginCacheCategories implements ResetInterface
{
    /** Shape of the `name` part of a category (manifest rule, contract C2). */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,40}$/';

    /** How long one process keeps the list; plugin changes also reset it. */
    private const KEEP_SECONDS = 30;

    /** @var array<string, int>|null category => lifetime in seconds, for enabled plugins */
    private ?array $enabled = null;

    private int $loadedAt = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Categories of enabled plugins.
     *
     * @return array<string, int> category (`<plugin id>.<name>`) => lifetime in seconds
     */
    public function enabled(): array
    {
        if ($this->enabled !== null && (time() - $this->loadedAt) < self::KEEP_SECONDS) {
            return $this->enabled;
        }

        $categories = [];
        foreach ($this->connection->fetchAllAssociative('SELECT plugin_id, manifest_json FROM plugins WHERE enabled = 1') as $row) {
            $pluginId = $row['plugin_id'] ?? null;
            if (!is_string($pluginId)) {
                continue;
            }
            $manifest = $row['manifest_json'] ?? null;
            foreach (self::categoriesOf($pluginId, is_string($manifest) ? $manifest : null) as $category => $ttl) {
                $categories[$category] = $ttl;
            }
        }
        ksort($categories);

        $this->enabled = $categories;
        $this->loadedAt = time();

        return $categories;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->enabled());
    }

    public function isRegistered(string $category): bool
    {
        return array_key_exists($category, $this->enabled());
    }

    /** The manifest lifetime of a registered category, or null. */
    public function lifetimeOf(string $category): ?int
    {
        return $this->enabled()[$category] ?? null;
    }

    /**
     * The categories one plugin's manifest declares, whether or not the
     * plugin is enabled (uninstall and purge clear them).
     *
     * @param array<string, mixed>|string|null $manifest the decoded manifest or its JSON text
     * @return array<string, int> category => lifetime in seconds
     */
    public static function categoriesOf(string $pluginId, array|string|null $manifest): array
    {
        if (is_string($manifest)) {
            $decoded = json_decode($manifest, true);
            $manifest = is_array($decoded) ? $decoded : null;
        }
        $declared = is_array($manifest) ? ($manifest['cacheCategories'] ?? null) : null;
        if (!is_array($declared)) {
            return [];
        }

        $categories = [];
        foreach ($declared as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $ttl = $entry['ttlSeconds'] ?? null;
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1 || !is_int($ttl)) {
                continue;
            }
            $categories[$pluginId . '.' . $name] = $ttl;
        }

        return $categories;
    }

    public function reset(): void
    {
        $this->enabled = null;
        $this->loadedAt = 0;
    }
}
