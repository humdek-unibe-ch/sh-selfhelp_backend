<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Archive\PluginArchivePromoter;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Security\PluginDependencyPolicy;

/**
 * Shared helper for the "standalone .shplugin archive" flow.
 *
 * A standalone archive carries its own backend Composer package under
 * `backend/package/`. Both install AND update workers must:
 *
 *   1. Promote the staging dir to its durable `var/plugins/<id>-<ver>/installed/`
 *      location BEFORE running `composer require` — otherwise the path
 *      repository would point at a transient dir that disappears at
 *      cleanup time.
 *   2. Build a synthetic `type: "path"` Composer repository pointing
 *      at the promoted `backend/package/` dir with `options.symlink: false`
 *      (real copy in vendor/, not a fragile symlink).
 *   3. Surface dependency-policy drift between the package's
 *      `composer.json#require` and the host-provided packages exposed
 *      via `var/plugin-composer/composer.json#provide`.
 *
 * Centralising this so the install handler and the update handler do
 * not drift was the original audit finding — managed-mode updates of
 * standalone archives were unreachable because only the install path
 * carried the logic.
 */
final class StandaloneArchiveComposerHelper
{
    public function __construct(
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly PluginDependencyPolicy $dependencyPolicy,
        private readonly PluginOperationRecorder $recorder,
    ) {
    }

    /**
     * True when the resolved source is a standalone `.shplugin` archive
     * with a staging dir on disk (the only case where the standalone
     * promotion flow applies).
     */
    public function isStandaloneArchive(ResolvedSource $resolved): bool
    {
        return $resolved->kind === ResolvedSource::KIND_ARCHIVE
            && $resolved->archiveMode === ResolvedSource::ARCHIVE_MODE_STANDALONE
            && $resolved->archiveStagingDir !== null;
    }

    /**
     * Promote the staging dir, return the [updated manifest array,
     * promoted backend/package/ dir] tuple. The promoted dir is what
     * the path repository points at. On failure the operation is
     * marked failed and `null` is returned so the caller can early-out.
     *
     * @param array<string,mixed> $manifestArray
     * @return array{0: array<string,mixed>, 1: string}|null
     */
    public function promoteStandaloneArchive(
        PluginOperation $operation,
        ResolvedSource $resolved,
        array $manifestArray,
        string $logEventPrefix = 'archive-promote',
    ): ?array {
        $this->recorder->appendLog($operation, $logEventPrefix . ':start', ['mode' => 'standalone'], 15);
        $manifestArray = $this->archivePromoter->promote(
            (string) $resolved->archiveStagingDir,
            $manifestArray,
        );
        $promotedId = $manifestArray['id'] ?? '';
        $promotedVersion = $manifestArray['version'] ?? '';
        $promotedBackendDir = $this->archivePromoter->installedDir(
            is_scalar($promotedId) ? (string) $promotedId : '',
            is_scalar($promotedVersion) ? (string) $promotedVersion : '',
        ) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'package';
        if (!is_dir($promotedBackendDir)) {
            $this->recorder->fail(
                $operation,
                new \RuntimeException(sprintf(
                    'Standalone archive: backend/package/ was not promoted to "%s".',
                    $promotedBackendDir,
                )),
                'archive-promote',
            );
            return null;
        }
        $this->recorder->appendLog($operation, $logEventPrefix . ':done', [
            'mode' => 'standalone',
            'backendDir' => $promotedBackendDir,
        ], 18);
        return [$manifestArray, $promotedBackendDir];
    }

    /**
     * Build the right Composer repository descriptor for the operation:
     *
     *   - Standalone archives: a synthetic `type: "path"` repo pointing
     *     at the promoted backend/package/ dir with `options.symlink: false`.
     *     Any `repository` declared in plugin.json is intentionally
     *     IGNORED — the archive's own backend package wins over the
     *     manifest's published repository pointer.
     *
     *   - Connected archives + every non-archive source: the repository
     *     declared in `backend.composer.repository` (Packagist when
     *     absent).
     *
     * @param array<string,mixed> $composer  plugin.json#backend.composer (from ResolvedSource)
     * @return array{type:string,url:string,reference?:string,options?:array<string,bool|int|string>}|null
     */
    public function resolveComposerRepository(
        array $composer,
        ResolvedSource $resolved,
        ?string $promotedBackendDir,
    ): ?array {
        if (
            $resolved->kind === ResolvedSource::KIND_ARCHIVE
            && $resolved->archiveMode === ResolvedSource::ARCHIVE_MODE_STANDALONE
            && $promotedBackendDir !== null
        ) {
            return [
                'type' => 'path',
                'url' => $promotedBackendDir,
                'options' => [
                    'symlink' => false,
                ],
            ];
        }
        if (isset($composer['repository']) && is_array($composer['repository'])) {
            /** @var array{type:string,url:string,reference?:string,options?:array<string,bool|int|string>} $repo */
            $repo = $composer['repository'];
            return $repo;
        }
        return null;
    }

    /**
     * Reads the package's `composer.json` from the promoted backend
     * dir, runs it through {@see PluginDependencyPolicy::inspect()},
     * and writes the report to the operation log. Soft check: never
     * fails the operation — Composer's solver runs immediately after
     * and is the authoritative gate. The log entry exists so
     * operators auditing a failed install/update can see the drift at
     * a glance.
     */
    public function logDependencyPolicyReport(PluginOperation $operation, string $promotedBackendDir): void
    {
        $composerPath = $promotedBackendDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerPath)) {
            return;
        }
        $raw = @file_get_contents($composerPath);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        $require = $data['require'] ?? [];
        if (!is_array($require) || $require === []) {
            return;
        }
        $stringKeyed = [];
        foreach ($require as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }
        if ($stringKeyed === []) {
            return;
        }
        $report = $this->dependencyPolicy->inspect($stringKeyed);
        if ($report['warnings'] === [] && $report['violations'] === []) {
            return;
        }
        $this->recorder->appendLog($operation, 'dependency-policy:report', [
            'warnings' => $report['warnings'],
            'violations' => $report['violations'],
        ], 18);
    }
}
