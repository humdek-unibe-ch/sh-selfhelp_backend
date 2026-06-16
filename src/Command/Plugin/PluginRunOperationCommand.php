<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Entity\Plugin\PluginOperation;
use App\Plugin\Lifecycle\PluginOperationRecorder;
use App\Plugin\Service\PluginCliFinalizer;
use App\Repository\Plugin\PluginOperationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Managed-mode runner.
 *
 * The admin UI records the operation in `requested` state and prints
 * a CLI command. The CI / CLI worker then runs this command with the
 * operation id; it inspects the operation type, runs composer/npm and
 * Doctrine migrations as appropriate, and calls the matching
 * `finalize-*` method on `PluginCliFinalizer`.
 *
 * The actual composer / npm invocations are out-of-scope for the
 * command itself — they are deployment-specific scripts. Here we
 * simply finalize the operation once the operator has run the
 * external package work.
 */
#[AsCommand(
    name: 'selfhelp:plugin:run-operation',
    description: 'Finalize a plugin operation after composer/npm + migrations have been executed.',
)]
final class PluginRunOperationCommand extends Command
{
    /** Statuses that are already terminal — never re-fail one of these. */
    private const TERMINAL_STATUSES = [
        PluginOperation::STATUS_SUCCEEDED,
        PluginOperation::STATUS_FAILED,
        PluginOperation::STATUS_CANCELLED,
        PluginOperation::STATUS_ROLLED_BACK,
    ];

    public function __construct(
        private readonly PluginCliFinalizer $finalizer,
        private readonly PluginOperationRepository $operations,
        private readonly PluginOperationRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operationId', InputArgument::REQUIRED, 'plugin_operations row id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $opIdRaw = $input->getArgument('operationId');
        $opId = is_numeric($opIdRaw) ? (int) $opIdRaw : 0;
        $operation = $this->operations->find($opId);
        if (!$operation instanceof PluginOperation) {
            $io->error(sprintf('Plugin operation #%d not found.', $opId));
            return Command::FAILURE;
        }

        $snapshots = $operation->getSnapshotsJson() ?? [];

        try {
            switch ($operation->getType()) {
                case PluginOperation::TYPE_INSTALL:
                    $manifestData = $snapshots['manifest'] ?? null;
                    if (!is_array($manifestData)) {
                        return $this->failOperation($io, $operation, 'Operation snapshot is missing the manifest payload; cannot finalize install.');
                    }
                    $this->finalizer->finalizeInstall($opId, self::toAssoc($manifestData));
                    break;
                case PluginOperation::TYPE_UPDATE:
                    $manifestData = $snapshots['newManifest'] ?? $snapshots['manifest'] ?? null;
                    if (!is_array($manifestData)) {
                        return $this->failOperation($io, $operation, 'Operation snapshot is missing the new manifest payload; cannot finalize update.');
                    }
                    $this->finalizer->finalizeUpdate($opId, self::toAssoc($manifestData));
                    break;
                case PluginOperation::TYPE_UNINSTALL:
                    $this->finalizer->finalizeUninstall($opId);
                    break;
                case PluginOperation::TYPE_PURGE:
                    $this->finalizer->finalizePurge($opId);
                    break;
                default:
                    return $this->failOperation($io, $operation, sprintf('Operation type "%s" cannot be finalized by run-operation.', $operation->getType()));
            }
        } catch (\Throwable $e) {
            // Guarantee a terminal status + final Mercure progress event even
            // when the finalizer throws AFTER markRunning. Otherwise the row is
            // stuck `running` (admin UI shows progress forever) and the
            // per-plugin lock blocks every later operation until its TTL.
            $this->failIfNotTerminal($operation, $e);
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Operation #%d (%s) finalized.', $opId, $operation->getType()));
        return Command::SUCCESS;
    }

    /**
     * Mark the operation failed (so the UI gets a terminal status + final
     * `plugin-operation-progress` event), print the message, and return the
     * console failure code.
     */
    private function failOperation(SymfonyStyle $io, PluginOperation $operation, string $message): int
    {
        $this->failIfNotTerminal($operation, new \RuntimeException($message));
        $io->error($message);
        return Command::FAILURE;
    }

    /**
     * Record a terminal `failed` status unless the operation already reached a
     * terminal state (e.g. the orchestrator's own catch already failed it).
     * Best-effort: the recorder's `fail()` has a raw-DBAL fallback for a
     * poisoned EntityManager, and we never let this recovery path mask the
     * original error or crash the command.
     */
    private function failIfNotTerminal(PluginOperation $operation, \Throwable $error): void
    {
        if (in_array($operation->getStatus(), self::TERMINAL_STATUSES, true)) {
            return;
        }
        try {
            $this->recorder->fail($operation, $error, 'run-operation');
        } catch (\Throwable) {
            // Swallow — `fail()` already logs + has a DBAL fallback.
        }
    }

    /**
     * Re-key a decoded JSON snapshot to a string-keyed map. The manifest
     * snapshot is always a JSON object at runtime; this only narrows the
     * key type for the finalizer signature without changing values.
     *
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function toAssoc(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}
