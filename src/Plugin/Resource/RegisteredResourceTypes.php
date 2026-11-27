<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

use App\Service\Core\LookupService;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The resource types that enabled plugins register (contract C1, C3, C4).
 *
 * A "registered type" is an entry of the `resourceTypes` lookup that an
 * **enabled** plugin owns (the catalogue synchronizer writes it from the
 * manifest's `lookups.extends`). This is the one list every host service
 * asks: the roles screen, `SyncTag` users, the cache resource scope, the
 * item services. Nothing else decides what a resource type is.
 *
 * It reads the database directly (one small query, kept for a few seconds
 * per process) and not through {@see \App\Service\Cache\Core\CacheService},
 * because the cache service itself asks this class which resource scopes
 * are allowed. {@see \App\Plugin\Cache\PluginCacheInvalidator} calls
 * {@see reset()} whenever plugins change.
 */
class RegisteredResourceTypes implements ResetInterface
{
    /** Shape of a type code (the same as the `res:` tag, contract C1). */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    /** How long one process keeps the list; plugin changes also reset it. */
    private const KEEP_SECONDS = 30;

    /** @var list<array{id: int, code: string, label: string, plugin_id: string}>|null */
    private ?array $types = null;

    private int $loadedAt = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{id: int, code: string, label: string, plugin_id: string}> sorted by label
     */
    public function all(): array
    {
        if ($this->types !== null && (time() - $this->loadedAt) < self::KEEP_SECONDS) {
            return $this->types;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.id, l.lookup_code, l.lookup_value, p.plugin_id '
            . 'FROM lookups l INNER JOIN plugins p ON p.id = l.id_plugins '
            . 'WHERE l.type_code = :type AND p.enabled = 1 '
            . 'ORDER BY l.lookup_value ASC, l.lookup_code ASC',
            ['type' => LookupService::RESOURCE_TYPES]
        );

        $types = [];
        foreach ($rows as $row) {
            $code = $row['lookup_code'] ?? null;
            $id = $row['id'] ?? null;
            $pluginId = $row['plugin_id'] ?? null;
            if (!is_string($code) || $code === '' || !is_numeric($id) || !is_string($pluginId)) {
                continue;
            }
            $label = $row['lookup_value'] ?? null;
            $types[] = [
                'id' => (int) $id,
                'code' => $code,
                'label' => is_string($label) && $label !== '' ? $label : $code,
                'plugin_id' => $pluginId,
            ];
        }

        $this->types = $types;
        $this->loadedAt = time();

        return $types;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (array $type): string => $type['code'], $this->all());
    }

    public function isRegistered(string $code): bool
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return false;
        }
        foreach ($this->all() as $type) {
            if ($type['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \InvalidArgumentException when no enabled plugin registers the type (a programming error)
     */
    public function assertRegistered(string $code): void
    {
        if (!$this->isRegistered($code)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a resource type of an enabled plugin.', $code));
        }
    }

    /** The `lookups.id` of a registered type, or null. */
    public function lookupId(string $code): ?int
    {
        foreach ($this->all() as $type) {
            if ($type['code'] === $code) {
                return $type['id'];
            }
        }

        return null;
    }

    /** The manifest id of the plugin that registers the type, or null. */
    public function pluginIdOf(string $code): ?string
    {
        foreach ($this->all() as $type) {
            if ($type['code'] === $code) {
                return $type['plugin_id'];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->types = null;
        $this->loadedAt = 0;
    }
}
