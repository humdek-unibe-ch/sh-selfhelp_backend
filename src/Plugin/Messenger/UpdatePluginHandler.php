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
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Lifecycle\PluginUpdater;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous worker for `UpdatePluginMessage`. Mirrors the install
 * handler: runs `composer require <package>:<newVersion>`, optionally
 * promotes archive artifacts, then calls `PluginUpdater::finalize()`.
 */
#[AsMessageHandler]
final class UpdatePluginHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginUpdater $updater,
        private readonly PackageManagerRunner $packageManager,
        private readonly PluginArchivePromoter $archivePromoter,
        private readonly InstallModeResolver $installModeResolver,
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

            if ($mode === 'managed') {
                $this->recorder->appendLog($operation, 'managed-runbook', [
                    'runbook' => [
                        'mode' => 'managed',
                        'command' => sprintf(
                            'composer require %s:%s --no-interaction --no-scripts',
                            $resolved->composer['package'] ?? '',
                            $resolved->composer['version'] ?? '',
                        ),
                        'finalize' => sprintf('php bin/console selfhelp:plugin:run-operation %d', $operation->getId()),
                    ],
                ], 25);
                return;
            }

            $package = (string) ($resolved->composer['package'] ?? '');
            $version = (string) ($resolved->composer['version'] ?? '');
            $repository = (isset($resolved->composer['repository']) && is_array($resolved->composer['repository']))
                ? $resolved->composer['repository']
                : null;

            $this->recorder->appendLog($operation, 'composer-require:start', ['package' => $package, 'version' => $version], 20);
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
                $manifestArray = $this->archivePromoter->promote($resolved->archiveStagingDir, $manifestArray);
            }

            $this->updater->finalize($operation, new PluginManifest($manifestArray));
        } catch (\Throwable $e) {
            $this->recorder->fail($operation, $e, 'update-worker');
            throw $e;
        }
    }
}
