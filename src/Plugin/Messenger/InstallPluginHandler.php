<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Archive\PluginArchivePromoter;
use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Plugin\Security\PluginDependencyPolicy;
use App\Repository\Plugin\PluginOperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous handler for `InstallPluginMessage`. Runs in the
 * `plugin_ops` Messenger worker. The unit of work:
 *
 *   1. Re-attach the `plugin_operations` row from the message id.
 *   2. In `managed` install mode → write a runbook line into the
 *      operation log and exit. The operator then runs
 *      `selfhelp:plugin:run-operation` from CI to finalize.
 *   3. In `development` / `trusted` install modes → run
 *      `composer require <package>:<version>` (optionally registering
 *      a temporary repo if the resolved source declares one) and
 *      stream the output into the operation log + Mercure topic.
 *   4. Promote .shplugin staging artifacts to public/plugin-artifacts/
 *      (when the source kind is `archive`) and rewrite the runtime
 *      URLs on the manifest array before finalize.
 *   5. Call `PluginInstaller::finalize($op, $manifest)`.
 *
 * Failure handling: any throwable inside the worker marks the
 * operation as failed via the recorder and re-throws so the worker
 * logs the failure too. The retry strategy in messenger.yaml is
 * `max_retries: 0` — operators kick off a fresh install rather than
 * retrying a partially-failed one.
 */
#[AsMessageHandler]
final class InstallPluginHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginInstaller $installer,
        private readonly PackageManagerRunner $packageManager,
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginDependencyPolicy $dependencyPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(InstallPluginMessage $message): void
    {
        $operation = $this->operations->find($message->operationId);
        if (!$operation instanceof PluginOperation) {
            $this->logger->error('Plugin install message received but operation row not found', [
                'operation_id' => $message->operationId,
            ]);
            return;
        }

        $manifestArray = $message->manifestArray;
        $resolved = $message->resolvedSource;
        $mode = $this->installModeResolver->resolve();

        try {
            $this->recorder->markRunning($operation, 'Starting plugin install worker');
            $this->recorder->appendLog($operation, 'install-mode-resolved', ['installMode' => $mode], 10);

            if ($mode === 'managed') {
                $this->emitManagedRunbook($operation, $manifestArray, $resolved);
                return;
            }

            $composer = $resolved->composer;
            $package = (string) ($composer['package'] ?? '');
            $version = (string) ($composer['version'] ?? '');
            if ($package === '' || $version === '') {
                $this->recorder->fail(
                    $operation,
                    new \RuntimeException('ResolvedSource is missing composer.package or composer.version.'),
                    'composer-require',
                );
                return;
            }

            // Phase 2a — for standalone archives we promote the
            // staging dir to its durable location BEFORE running
            // composer require. The Composer path repo must point at
            // the promoted backend/package/ (under var/plugins/<id>-
            // <ver>/installed/) rather than the transient staging dir,
            // otherwise the symlink/copy would dangle the moment the
            // staging dir is cleaned up.
            $isStandaloneArchive = (
                $resolved->kind === ResolvedSource::KIND_ARCHIVE
                && $resolved->archiveMode === ResolvedSource::ARCHIVE_MODE_STANDALONE
                && $resolved->archiveStagingDir !== null
            );
            $promotedBackendDir = null;
            if ($isStandaloneArchive) {
                $this->recorder->appendLog($operation, 'archive-promote:start', ['mode' => 'standalone'], 15);
                $manifestArray = $this->archivePromoter->promote(
                    (string) $resolved->archiveStagingDir,
                    $manifestArray,
                );
                $promotedBackendDir = $this->archivePromoter->installedDir(
                    (string) ($manifestArray['id'] ?? ''),
                    (string) ($manifestArray['version'] ?? ''),
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
                    return;
                }
                $this->recorder->appendLog($operation, 'archive-promote:done', ['mode' => 'standalone', 'backendDir' => $promotedBackendDir], 18);
            }

            $repository = $this->resolveComposerRepository($composer, $resolved, $promotedBackendDir);

            // Soft check — for standalone archives the package's
            // composer.json is on disk in the promoted backend dir, so
            // we can surface host-vs-plugin dependency drift to the
            // operation log BEFORE composer fires. Connected / non-
            // archive sources skip the check; Composer's solver will
            // surface the same drift at solve-time.
            if ($promotedBackendDir !== null) {
                $this->logDependencyPolicyReport($operation, $promotedBackendDir);
            }

            $this->recorder->appendLog($operation, 'composer-require:start', [
                'package' => $package,
                'version' => $version,
                'repository' => $repository,
                'archiveMode' => $resolved->kind === ResolvedSource::KIND_ARCHIVE ? $resolved->archiveMode : null,
            ], 20);

            $result = $this->packageManager->requireComposerPackageFromRepository(
                $package,
                $version,
                $repository,
                function (string $line, string $stream) use ($operation): void {
                    if ($line === '') {
                        return;
                    }
                    $this->recorder->appendLog($operation, 'composer-require:line', [
                        'stream' => $stream,
                        'line' => $line,
                    ]);
                },
            );

            if (!$result->success) {
                $this->recorder->fail($operation, new \RuntimeException(sprintf(
                    'composer require failed (exit %d): %s',
                    $result->exitCode,
                    trim($result->stderr ?: $result->stdout),
                )), 'composer-require');
                return;
            }
            $this->recorder->appendLog($operation, 'composer-require:done', ['exitCode' => $result->exitCode], 60);

            // Connected archives still promote AFTER composer require —
            // the install dir is only needed to serve the runtime ESM,
            // so the order doesn't matter. Standalone archives already
            // promoted above; skip the second promote.
            if (!$isStandaloneArchive && $resolved->kind === ResolvedSource::KIND_ARCHIVE && $resolved->archiveStagingDir !== null) {
                $this->recorder->appendLog($operation, 'archive-promote:start', ['mode' => 'connected'], 65);
                $manifestArray = $this->archivePromoter->promote(
                    $resolved->archiveStagingDir,
                    $manifestArray,
                );
                $this->recorder->appendLog($operation, 'archive-promote:done', ['mode' => 'connected'], 70);
            }

            $manifest = new PluginManifest($manifestArray);
            $this->installer->finalize($operation, $manifest);
            // recorder->succeed() is called inside installer->finalize().
        } catch (\Throwable $e) {
            $this->recorder->fail($operation, $e, 'install-worker');
            $this->logger->error('Plugin install worker failed', [
                'operation_id' => $operation->getId(),
                'plugin_id' => $operation->getPluginId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $manifestArray
     */
    private function emitManagedRunbook(PluginOperation $operation, array $manifestArray, ResolvedSource $resolved): void
    {
        $package = (string) ($resolved->composer['package'] ?? ($manifestArray['id'] ?? ''));
        $version = (string) ($resolved->composer['version'] ?? ($manifestArray['version'] ?? ''));
        $runbook = [
            'mode' => 'managed',
            'command' => sprintf('composer require %s:%s --no-interaction --no-scripts', $package, $version),
            'finalize' => sprintf('php bin/console selfhelp:plugin:run-operation %d', $operation->getId()),
            'repository' => $resolved->composer['repository'] ?? null,
            'archiveStagingDir' => $resolved->archiveStagingDir,
            'archiveMode' => $resolved->kind === ResolvedSource::KIND_ARCHIVE ? $resolved->archiveMode : null,
            'archiveBackendDir' => $resolved->archiveBackendDir,
            'note' => 'Managed install mode: a CI/CD operator must run the composer command, deploy, then call selfhelp:plugin:run-operation. Operation will stay in "running" state until finalize is called. For standalone archives the operator must first register a path repo pointing at archiveBackendDir before running composer require.',
        ];
        $this->recorder->appendLog($operation, 'managed-runbook', ['runbook' => $runbook], 25);
        // Operation intentionally stays running — it is waiting for the
        // operator. finalize() will move it to succeeded/failed.
    }

    /**
     * Reads the package's `composer.json` from the promoted backend
     * dir, runs it through {@see PluginDependencyPolicy::inspect()},
     * and writes the report to the operation log. Soft check: never
     * fails the install — Composer's solver runs immediately after
     * and is the authoritative gate. The log entry exists so
     * operators auditing a failed install can see the drift at a
     * glance.
     */
    private function logDependencyPolicyReport(PluginOperation $operation, string $promotedBackendDir): void
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

    /**
     * Picks the right Composer repository descriptor for the operation:
     *
     *   - For standalone archives (Phase 2a): a synthetic `type: "path"`
     *     repo pointing at the promoted backend/package/ dir with
     *     `options.symlink: false` so vendor/ holds a real copy rather
     *     than a fragile symlink. Any `repository` declared in
     *     plugin.json is intentionally IGNORED here — the archive's
     *     own backend package wins over the manifest's published
     *     repository pointer.
     *
     *   - For connected archives and every non-archive source: the
     *     repository declared in `backend.composer.repository`
     *     (Packagist when absent). Phase-1 behaviour preserved.
     *
     * @param array<string,mixed> $composer plugin.json#backend.composer (from ResolvedSource)
     * @return array{type:string,url:string,reference?:string,options?:array<string,bool|int|string>}|null
     */
    private function resolveComposerRepository(array $composer, ResolvedSource $resolved, ?string $promotedBackendDir): ?array
    {
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
}
