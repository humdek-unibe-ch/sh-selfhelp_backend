<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use App\Service\Cache\Core\CacheService;

/**
 * Persistent, admin-toggleable maintenance-mode switch for the CURRENT instance.
 *
 * Two layers, OR-combined:
 *   - an env hard switch (`SELFHELP_MAINTENANCE_MODE=true`) the SelfHelp Manager
 *     can inject per instance. This FORCES maintenance on and cannot be cleared
 *     from the web — the API reports it as `forced_by_env` so the UI disables the
 *     toggle and tells the operator to clear it in the instance `.env`;
 *   - a persistent state file `var/maintenance_mode.lock` (small JSON) that the
 *     admin API toggles. It survives restarts without editing `.env` (the same
 *     pattern {@see \App\Plugin\Lifecycle\PluginSafeMode} uses for safe mode).
 *
 * The state carries an operator-facing message plus audit fields (who/when). It
 * NEVER contains secrets — only a human note and the acting user id.
 *
 * The operator message reaches visitors through the `{{system.maintenance_message}}`
 * variable, which `PageService` resolves and bakes into the cached, rendered
 * `pages`/`sections` payload. So toggling maintenance or editing the message MUST
 * invalidate those cache categories, otherwise the public page keeps serving the
 * previous (or empty) message until an unrelated cache bump. The cache is an
 * optional dependency: when absent (pure unit tests) the toggle still works, it
 * just skips the (irrelevant) invalidation.
 */
class MaintenanceModeService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly bool $envForced,
        private readonly ?CacheService $cache = null,
    ) {
    }

    /** Whether maintenance mode is currently active (env hard switch OR the file). */
    public function isEnabled(): bool
    {
        return $this->envForced || is_file($this->statePath());
    }

    /** Whether the env hard switch is forcing maintenance on (not web-clearable). */
    public function isForcedByEnv(): bool
    {
        return $this->envForced;
    }

    /**
     * @return array{enabled: bool, forced_by_env: bool, message: string, since: string, updated_by: string}
     */
    public function getState(): array
    {
        $file = $this->readStateFile();
        $enabled = $this->envForced || $file !== null;

        $message = is_array($file) && isset($file['message']) && is_string($file['message']) ? $file['message'] : '';
        $since = is_array($file) && isset($file['since']) && is_string($file['since']) ? $file['since'] : '';
        $updatedBy = is_array($file) && isset($file['updated_by']) && is_string($file['updated_by']) ? $file['updated_by'] : '';

        if ($message === '' && $this->envForced) {
            $message = 'Maintenance mode is enforced by server configuration (SELFHELP_MAINTENANCE_MODE).';
        }
        if ($updatedBy === '' && $this->envForced) {
            $updatedBy = 'server-config';
        }

        return [
            'enabled' => $enabled,
            'forced_by_env' => $this->envForced,
            'message' => $message,
            'since' => $since,
            'updated_by' => $updatedBy,
        ];
    }

    /**
     * Turn maintenance mode on (writes the state file). No-op semantics when the
     * env hard switch is already forcing it on: the file is still written so the
     * operator note/audit fields are recorded, but `forced_by_env` stays true.
     *
     * @return array{enabled: bool, forced_by_env: bool, message: string, since: string, updated_by: string}
     */
    public function enable(string $message, string $actor): array
    {
        $path = $this->statePath();
        @mkdir(dirname($path), 0o775, true);

        $payload = [
            'message' => $message,
            'since' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'updated_by' => $actor,
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        $this->invalidateRenderedContent();

        return $this->getState();
    }

    /**
     * Turn maintenance mode off (removes the state file). If the env hard switch
     * is set, the instance STAYS in maintenance and the returned state reflects
     * that (`enabled` + `forced_by_env` remain true).
     *
     * @return array{enabled: bool, forced_by_env: bool, message: string, since: string, updated_by: string}
     */
    public function disable(): array
    {
        $path = $this->statePath();
        if (is_file($path)) {
            @unlink($path);
        }
        $this->invalidateRenderedContent();

        return $this->getState();
    }

    /**
     * Drop the rendered-page caches that may have baked in the previous
     * `{{system.maintenance_message}}` value. Generation-bump invalidation is
     * O(1); maintenance toggles are rare, so a category-wide bump (rather than a
     * single-page scope) is the safe choice — the message variable is global and
     * an operator may surface it on more than just the seeded maintenance page.
     */
    private function invalidateRenderedContent(): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();
        $this->cache->withCategory(CacheService::CATEGORY_SECTIONS)->invalidateCategory();
    }

    /**
     * @return array<array-key, mixed>|null null when no file exists; an array
     *                                       (possibly empty) when the file exists
     */
    private function readStateFile(): ?array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function statePath(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'maintenance_mode.lock';
    }
}
