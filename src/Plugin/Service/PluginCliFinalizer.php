<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Service;

use App\Entity\Plugin\PluginOperation;
use App\Exception\ServiceException;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\Lifecycle\PluginUninstaller;
use App\Plugin\Lifecycle\PluginUpdater;
use App\Plugin\Manifest\PluginManifest;
use App\Repository\Plugin\PluginOperationRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * CLI-only finalizer for managed-mode plugin operations.
 *
 * Wraps `PluginInstaller::finalize()` / `PluginUpdater::finalize()` /
 * `PluginUninstaller::finalize()` / `PluginPurger::finalize()` so the
 * `selfhelp:plugin:run-operation` CLI command can finalize an operation
 * after a managed-mode operator has executed composer + migrations by
 * hand. The Messenger worker never goes through this service — it calls
 * the lifecycle orchestrators directly. Keeping these methods off
 * `PluginAdminService` stops admin controllers from accidentally
 * invoking the finalize step from an HTTP request.
 */
final class PluginCliFinalizer
{
    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginUpdater $updater,
        private readonly PluginUninstaller $uninstaller,
        private readonly PluginPurger $purger,
        private readonly PluginOperationRepository $operations,
    ) {
    }

    /**
     * @param array<string,mixed> $manifestData
     */
    public function finalizeInstall(int $operationId, array $manifestData): void
    {
        $op = $this->mustFindOperation($operationId);
        $this->installer->finalize($op, new PluginManifest($manifestData));
    }

    /**
     * @param array<string,mixed> $manifestData
     */
    public function finalizeUpdate(int $operationId, array $manifestData): void
    {
        $op = $this->mustFindOperation($operationId);
        $this->updater->finalize($op, new PluginManifest($manifestData));
    }

    public function finalizeUninstall(int $operationId): void
    {
        $op = $this->mustFindOperation($operationId);
        $this->uninstaller->finalize($op);
    }

    public function finalizePurge(int $operationId): void
    {
        $op = $this->mustFindOperation($operationId);
        $this->purger->finalize($op);
    }

    private function mustFindOperation(int $operationId): PluginOperation
    {
        $op = $this->operations->find($operationId);
        if (!$op instanceof PluginOperation) {
            throw new ServiceException(
                sprintf('Plugin operation #%d not found.', $operationId),
                Response::HTTP_NOT_FOUND,
            );
        }
        return $op;
    }
}
