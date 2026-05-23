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

            $this->recorder->appendLog($operation, 'composer-require:start', [
                'package' => $package,
                'version' => $version,
                'repository' => $composer['repository'] ?? null,
            ], 20);

            $repository = (isset($composer['repository']) && is_array($composer['repository']))
                ? $composer['repository']
                : null;
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

            if ($resolved->kind === ResolvedSource::KIND_ARCHIVE && $resolved->archiveStagingDir !== null) {
                $this->recorder->appendLog($operation, 'archive-promote:start', null, 65);
                $manifestArray = $this->archivePromoter->promote(
                    $resolved->archiveStagingDir,
                    $manifestArray,
                );
                $this->recorder->appendLog($operation, 'archive-promote:done', null, 70);
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
            'note' => 'Managed install mode: a CI/CD operator must run the composer command, deploy, then call selfhelp:plugin:run-operation. Operation will stay in "running" state until finalize is called.',
        ];
        $this->recorder->appendLog($operation, 'managed-runbook', ['runbook' => $runbook], 25);
        // Operation intentionally stays running — it is waiting for the
        // operator. finalize() will move it to succeeded/failed.
    }
}
