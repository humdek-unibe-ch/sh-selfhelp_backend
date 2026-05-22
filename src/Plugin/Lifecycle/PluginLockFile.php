<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

/**
 * Wraps `selfhelp.plugins.lock.json`.
 *
 * The lock file is the deterministic source of truth for installed
 * plugin versions, migration hashes, checksums, capabilities, owned
 * styles/topics/lookups, mobile package pins, and the install mode
 * that produced the file. It is written atomically (tmp file + rename)
 * and never edited by hand.
 *
 * The PHP DTO here mirrors `IPluginLock` from
 * `@selfhelp/shared/plugin-sdk`. Both must stay in sync; the
 * `validate-plugin` / `plugin-host-check` GitHub workflows compare
 * `selfhelp.plugins.lock.json` against the JSON Schema at
 * `docs/plugins/plugin-lock.schema.json`.
 */
final class PluginLockFile
{
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $generatedBy,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly string $installMode,
        /** @var list<array<string,mixed>> */
        public readonly array $plugins,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'generatedBy' => $this->generatedBy,
            'generatedAt' => $this->generatedAt->format(DATE_ATOM),
            'installMode' => $this->installMode,
            'plugins' => $this->plugins,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $generatedAt = isset($data['generatedAt']) ? new \DateTimeImmutable((string) $data['generatedAt']) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new self(
            (string) ($data['schemaVersion'] ?? '1.0'),
            (string) ($data['generatedBy'] ?? 'unknown'),
            $generatedAt,
            (string) ($data['installMode'] ?? 'managed'),
            isset($data['plugins']) && is_array($data['plugins']) ? array_values($data['plugins']) : [],
        );
    }
}
