<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Archive\PluginArchivePromoter;
use App\Plugin\Archive\PluginRuntimeArtifactFetcher;
use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
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
 * The standalone-archive flow (promote BEFORE composer + synthetic
 * path repo + dependency-policy report) is shared with
 * {@see UpdatePluginHandler} via {@see StandaloneArchiveComposerHelper}.
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
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginInstaller $installer,
        private readonly PackageManagerRunner $packageManager,
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly PluginRuntimeArtifactFetcher $runtimeArtifactFetcher,
        private readonly InstallModeResolver $installModeResolver,
        private readonly StandaloneArchiveComposerHelper $standaloneHelper,
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

            // For standalone archives we promote the staging dir to
            // its durable location BEFORE running composer require.
            // The Composer path repo must point at the promoted
            // backend/package/ (under var/plugins/<id>-<ver>/installed/)
            // rather than the transient staging dir, otherwise the
            // symlink/copy would dangle the moment the staging dir is
            // cleaned up.
            $promotedBackendDir = null;
            $isStandaloneArchive = $this->standaloneHelper->isStandaloneArchive($resolved);
            if ($isStandaloneArchive) {
                $promoteResult = $this->standaloneHelper->promoteStandaloneArchive(
                    $operation,
                    $resolved,
                    $manifestArray,
                );
                if ($promoteResult === null) {
                    return;
                }
                [$manifestArray, $promotedBackendDir] = $promoteResult;
            }

            $repository = $this->standaloneHelper->resolveComposerRepository(
                $composer,
                $resolved,
                $promotedBackendDir,
            );

            // Soft check — for standalone archives the package's
            // composer.json is on disk in the promoted backend dir, so
            // we can surface host-vs-plugin dependency drift to the
            // operation log BEFORE composer fires. Connected / non-
            // archive sources skip the check; Composer's solver will
            // surface the same drift at solve-time.
            if ($promotedBackendDir !== null) {
                $this->standaloneHelper->logDependencyPolicyReport($operation, $promotedBackendDir);
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

            // Registry / URL installs: download the published runtime
            // bundle (plugin.esm.js + optional plugin.css) from the
            // signed-payload URLs into public/plugin-artifacts/<id>-<ver>/.
            // The host then serves the bundle from its own origin —
            // mandatory because plugin bundles import host-only paths
            // such as /api/plugins/runtime-shim/* which only resolve
            // when same-origin with the SelfHelp host. Archive installs
            // already wrote the same files above; paste installs use a
            // dev server and never hit the registry artifact URL.
            if ($resolved->kind === ResolvedSource::KIND_REGISTRY || $resolved->kind === ResolvedSource::KIND_URL) {
                $manifestArray = $this->fetchAndRewriteRuntimeArtifacts($operation, $resolved, $manifestArray);
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
     * Download the published runtime bundle into
     * public/plugin-artifacts/<id>-<ver>/ and rewrite the manifest's
     * frontend.runtime.entrypoint / stylesheet to the resulting
     * host-relative paths. See {@see PluginRuntimeArtifactFetcher}
     * for the rationale (plugin bundles import host-only paths and
     * must be served same-origin).
     *
     * @param array<string,mixed> $manifestArray
     * @return array<string,mixed>
     */
    private function fetchAndRewriteRuntimeArtifacts(
        PluginOperation $operation,
        ResolvedSource $resolved,
        array $manifestArray,
    ): array {
        $pluginId = $this->stringFieldOrEmpty($manifestArray, 'id');
        $version = $this->stringFieldOrEmpty($manifestArray, 'version');
        $this->recorder->appendLog($operation, 'runtime-artifact-fetch:start', [
            'kind' => $resolved->kind,
            'entrypointUrl' => $this->resolvedRuntimeString($resolved, 'entrypointUrl'),
            'stylesheetUrl' => $this->resolvedRuntimeString($resolved, 'stylesheetUrl'),
        ], 72);
        $promoted = $this->runtimeArtifactFetcher->fetchAndPromote(
            pluginId: $pluginId,
            version: $version,
            resolvedRuntime: $resolved->runtime,
            expectedChecksums: $resolved->expectedChecksums,
        );

        $frontend = isset($manifestArray['frontend']) && is_array($manifestArray['frontend']) ? $manifestArray['frontend'] : [];
        $runtime = isset($frontend['runtime']) && is_array($frontend['runtime']) ? $frontend['runtime'] : [];
        $runtime['entrypoint'] = $promoted['entrypointWebPath'];
        if ($promoted['stylesheetWebPath'] !== null) {
            $runtime['stylesheet'] = $promoted['stylesheetWebPath'];
        }
        $frontend['runtime'] = $runtime;
        $manifestArray['frontend'] = $frontend;

        $this->recorder->appendLog($operation, 'runtime-artifact-fetch:done', [
            'entrypointWebPath' => $promoted['entrypointWebPath'],
            'stylesheetWebPath' => $promoted['stylesheetWebPath'],
            'chunkCount' => count($promoted['downloadedChunks']),
        ], 75);

        return $manifestArray;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function stringFieldOrEmpty(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    private function resolvedRuntimeString(ResolvedSource $resolved, string $key): ?string
    {
        $value = $resolved->runtime[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
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
}
