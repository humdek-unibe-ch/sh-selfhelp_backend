<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:rollback',
    description: 'Roll back a failed plugin operation (file/config only — never auto-rolls back DB migrations).',
)]
final class PluginRollbackCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('operationId', InputArgument::REQUIRED, 'Failed plugin_operations row id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $opIdRaw = $input->getArgument('operationId');
        $opId = is_numeric($opIdRaw) ? (int) $opIdRaw : 0;
        try {
            $op = $this->pluginAdminService->rollback($opId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Operation %d rolled back (status=%s).', (int) $op['id'], $op['status']));
        return Command::SUCCESS;
    }
}
