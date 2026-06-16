<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Messenger;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous handler for `PurgePluginMessage`. Mirrors the uninstall
 * handler — purge is "uninstall + destructive data removal", so the
 * composer side is identical:
 *
 *   1. Re-attach the `plugin_operations` row by id.
 *   2. In `managed` install mode → write a `managed-runbook` entry
 *      (the `composer remove` command) into the operation log and exit,
 *      leaving the row `running`. The SelfHelp Manager drains it: runs
 *      composer remove, then `selfhelp:plugin:run-operation <id>` which
 *      invokes {@see PluginPurger::finalize()}.
 *   3. In `development` / `trusted` modes → run `composer remove
 *      <package>`, stream the output into the operation log, then call
 *      `PluginPurger::finalize()` to drop the plugin-owned tables, delete
 *      `id_plugins`-tagged rows, remove the plugin row, and regenerate
 *      artefacts.
 *
 * The runbook command is intentionally byte-compatible with the uninstall
 * runbook (`composer remove <package> …`) because the manager parses that
 * literal command string to recover the composer coordinates.
 */
#[AsMessageHandler]
final class PurgePluginHandler
{
    public function __construct(
        private readonly PluginOperationRepository $operations,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginPurger $purger,
        private readonly PackageManagerRunner $packageManager,
        private readonly InstallModeResolver $installModeResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgePluginMessage $message): void
    {
        $operation = $this->operations->find($message->operationId);
        if (!$operation instanceof PluginOperation) {
            $this->logger->error('Plugin purge message received but operation row not found', [
                'operation_id' => $message->operationId,
            ]);
            return;
        }

        try {
            $plugin = $this->plugins->findOneByPluginId($message->pluginId);
            $package = $plugin?->getBackendPackage();
            $mode = $this->installModeResolver->resolve();

            $this->recorder->markRunning($operation, 'Starting plugin purge worker');

            if ($mode === 'managed') {
                $this->recorder->appendLog($operation, 'managed-runbook', [
                    'runbook' => [
                        'mode' => 'managed',
                        'command' => $package !== null && $package !== ''
                            ? sprintf('composer remove %s --no-interaction --no-scripts', $package)
                            : 'No composer package to remove (frontend-only plugin).',
                        'finalize' => sprintf('php bin/console selfhelp:plugin:run-operation %d', $operation->getId()),
                        'note' => 'Managed purge: the operator runs composer remove, deploys, then calls selfhelp:plugin:run-operation to DROP plugin-owned tables, delete plugin-tagged data, and remove the plugin row. Irreversible.',
                    ],
                ], 25);
                // Operation stays running until the operator finalizes.
                return;
            }

            if ($package !== null && $package !== '') {
                $this->recorder->appendLog($operation, 'composer-remove:start', ['package' => $package], 20);
                $result = $this->packageManager->removeComposerPackage(
                    $package,
                    function (string $line, string $stream) use ($operation): void {
                        if ($line === '') {
                            return;
                        }
                        $this->recorder->appendLog($operation, 'composer-remove:line', [
                            'stream' => $stream,
                            'line' => $line,
                        ]);
                    },
                );
                if (!$result->success) {
                    $this->recorder->fail($operation, new \RuntimeException(sprintf(
                        'composer remove failed during purge (exit %d): %s',
                        $result->exitCode,
                        trim($result->stderr ?: $result->stdout),
                    )), 'composer-remove');
                    return;
                }
                $this->recorder->appendLog($operation, 'composer-remove:done', ['exitCode' => $result->exitCode], 60);
            } else {
                $this->recorder->appendLog($operation, 'composer-remove:skipped', [
                    'reason' => 'No backend.composer.package declared (frontend-only plugin).',
                ], 60);
            }

            $this->purger->finalize($operation);
            // recorder->succeed() is called inside finalize().
        } catch (\Throwable $e) {
            $this->recorder->fail($operation, $e, 'purge-worker');
            throw $e;
        }
    }
}
