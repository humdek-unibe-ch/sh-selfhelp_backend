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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:purge',
    description: 'Destructively remove a plugin (drops plugin-owned tables; deletes plugin-tagged data).',
)]
final class PluginPurgeCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginId', InputArgument::REQUIRED, 'Plugin id to purge.');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to proceed non-interactively.');
        $this->addOption('i-understand-this-is-irreversible', null, InputOption::VALUE_NONE, 'Second confirmation flag (interactive only).');
        $this->addOption(
            'backup-before',
            null,
            InputOption::VALUE_NONE,
            'Run the configured PluginBackupHook before purging so plugin-owned tables can be restored if needed.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginIdArg = $input->getArgument('pluginId');
        $pluginId = is_string($pluginIdArg) ? $pluginIdArg : '';

        if (!$input->getOption('confirm')) {
            $io->error('Refusing to purge without --confirm.');
            return Command::FAILURE;
        }

        $io->warning('Purge will DROP plugin-owned tables and DELETE plugin-tagged data. This is irreversible.');
        if ($input->isInteractive() && !$input->getOption('i-understand-this-is-irreversible')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(sprintf('Type "yes" to acknowledge irreversibility for "%s": ', $pluginId), false);
            if (!$helper->ask($input, $output, $question)) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
            $idQuestion = new Question(sprintf('Re-type the plugin id (%s) to confirm: ', $pluginId));
            $answer = $helper->ask($input, $output, $idQuestion);
            $confirmId = is_string($answer) ? $answer : '';
            if ($confirmId !== $pluginId) {
                $io->error('Plugin id does not match. Aborted.');
                return Command::FAILURE;
            }
        }

        try {
            $this->pluginAdminService->purge($pluginId, $pluginId, (bool) $input->getOption('backup-before'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Plugin "%s" purged.', $pluginId));
        return Command::SUCCESS;
    }
}
