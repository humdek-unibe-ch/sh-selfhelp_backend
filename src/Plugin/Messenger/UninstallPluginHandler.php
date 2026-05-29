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
use App\Plugin\Lifecycle\PluginUninstaller;
use App\Plugin\PackageManager\PackageManagerRunner;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asynchronous handler for `UninstallPluginMessage`. Mirrors the install
 * handler:
 *
 *   1. Re-attach the `plugin_operations` row by id.
 *   2. In `managed` install mode → write a runbook entry into the
 *      operation log and exit. The operator runs `composer remove`
 *      themselves and then triggers
 *      `selfhelp:plugin:run-operation <id>` to invoke
 *      {@see PluginUninstaller::finalize()}.
 *   3. In `development` / `trusted` install modes → run
 *      `composer remove <package>` and stream the output into the
 *      operation log, then call `PluginUninstaller::finalize()` to
 *      delete the plugin row, regenerate `selfhelp_plugin_bundles.php`,
 *      update the lock file, and dispatch `PluginUninstalledEvent`.
 */
#[AsMessageHandler]
final class UninstallPluginHandler
{
    public function __construct(
        private readonly PluginOperationRepository $operations,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginUninstaller $uninstaller,
        private readonly PackageManagerRunner $packageManager,
        private readonly InstallModeResolver $installModeResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(UninstallPluginMessage $message): void
    {
        $operation = $this->operations->find($message->operationId);
        if (!$operation instanceof PluginOperation) {
            $this->logger->error('Plugin uninstall message received but operation row not found', [
                'operation_id' => $message->operationId,
            ]);
            return;
        }

        try {
            $plugin = $this->plugins->findOneByPluginId($message->pluginId);
            $package = $plugin?->getBackendPackage();
            $mode = $this->installModeResolver->resolve();

            $this->recorder->markRunning($operation, 'Starting plugin uninstall worker');

            if ($mode === 'managed') {
                $this->recorder->appendLog($operation, 'managed-runbook', [
                    'runbook' => [
                        'mode' => 'managed',
                        'command' => $package !== null && $package !== ''
                            ? sprintf('composer remove %s --no-interaction --no-scripts', $package)
                            : 'No composer package to remove (frontend-only plugin).',
                        'finalize' => sprintf('php bin/console selfhelp:plugin:run-operation %d', $operation->getId()),
                        'note' => 'Managed uninstall: the operator runs composer remove, deploys, then calls selfhelp:plugin:run-operation to delete the plugin row + regenerate the lock file.',
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
                        'composer remove failed (exit %d): %s',
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

            $this->uninstaller->finalize($operation);
            // recorder->succeed() is called inside finalize().
        } catch (\Throwable $e) {
            $this->recorder->fail($operation, $e, 'uninstall-worker');
            throw $e;
        }
    }
}
