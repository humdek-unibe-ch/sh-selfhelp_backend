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
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Lifecycle\PluginUpdater;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous worker for `UpdatePluginMessage`. Mirrors the install
 * handler exactly: managed-mode emits a runbook, development/trusted
 * modes run `composer require <package>:<newVersion>`, promote any
 * new `.shplugin` artifacts, then call `PluginUpdater::finalize()`.
 *
 * The standalone-archive flow (promote BEFORE composer + synthetic
 * path repo + dependency-policy report) is shared with
 * {@see InstallPluginHandler} via {@see StandaloneArchiveComposerHelper}.
 * Without this, updates of plugins originally installed from a
 * standalone .shplugin archive would fail at composer require because
 * the path repo was never set up.
 */
#[AsMessageHandler]
final class UpdatePluginHandler
{
    public function __construct(
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginUpdater $updater,
        private readonly PackageManagerRunner $packageManager,
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly PluginRuntimeArtifactFetcher $runtimeArtifactFetcher,
        private readonly InstallModeResolver $installModeResolver,
        private readonly StandaloneArchiveComposerHelper $standaloneHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdatePluginMessage $message): void
    {
        $operation = $this->operations->find($message->operationId);
        if (!$operation instanceof PluginOperation) {
            $this->logger->error('Plugin update message received but operation row not found', [
                'operation_id' => $message->operationId,
            ]);
            return;
        }

        $manifestArray = $message->manifestArray;
        $resolved = $message->resolvedSource;
        $mode = $this->installModeResolver->resolve();

        try {
            $this->recorder->markRunning($operation, 'Starting plugin update worker');
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

            // Standalone archives carry their own backend Composer
            // package under backend/package/. We must promote the
            // staging dir BEFORE composer require so the synthetic
            // path repo points at a durable location (not the staging
            // dir which gets cleaned up). Without this, managed-mode
            // updates of standalone-installed plugins fail at composer
            // because there is no repository to find the new version in.
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

            // Connected archives promote AFTER composer require — same
            // ordering as InstallPluginHandler. Standalone archives
            // already promoted above; skip the second promote.
            if (!$isStandaloneArchive && $resolved->kind === ResolvedSource::KIND_ARCHIVE && $resolved->archiveStagingDir !== null) {
                $this->recorder->appendLog($operation, 'archive-promote:start', ['mode' => 'connected'], 65);
                $manifestArray = $this->archivePromoter->promote($resolved->archiveStagingDir, $manifestArray);
                $this->recorder->appendLog($operation, 'archive-promote:done', ['mode' => 'connected'], 70);
            }

            // Registry / URL updates: re-fetch the published runtime
            // bundle for the NEW version into a sibling artifact dir.
            // Same rationale as InstallPluginHandler — plugin bundles
            // import host-only paths and must be served same-origin.
            // See {@see PluginRuntimeArtifactFetcher}.
            if ($resolved->kind === ResolvedSource::KIND_REGISTRY || $resolved->kind === ResolvedSource::KIND_URL) {
                $manifestArray = $this->fetchAndRewriteRuntimeArtifacts($operation, $resolved, $manifestArray);
            }

            $this->updater->finalize($operation, new PluginManifest($manifestArray));
        } catch (\Throwable $e) {
            $this->recorder->fail($operation, $e, 'update-worker');
            $this->logger->error('Plugin update worker failed', [
                'operation_id' => $operation->getId(),
                'plugin_id' => $operation->getPluginId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download the published runtime bundle for the new version into
     * public/plugin-artifacts/<id>-<ver>/ and rewrite the manifest's
     * frontend.runtime.entrypoint / stylesheet to the host-relative
     * paths. Mirrors {@see InstallPluginHandler::fetchAndRewriteRuntimeArtifacts()}.
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
            'note' => 'Managed update mode: a CI/CD operator must run the composer command, deploy, then call selfhelp:plugin:run-operation. Operation will stay in "running" state until finalize is called. For standalone archives the operator must first register a path repo pointing at archiveBackendDir before running composer require.',
        ];
        $this->recorder->appendLog($operation, 'managed-runbook', ['runbook' => $runbook], 25);
    }
}
