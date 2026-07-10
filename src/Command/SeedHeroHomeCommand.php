<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\CMS\Admin\HeroHomeSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:examples:seed-hero-home',
    description: 'Import the hero-home example onto the system home page when it is still the untouched default.',
)]
class SeedHeroHomeCommand extends Command
{
    public function __construct(
        private readonly HeroHomeSeedService $heroHomeSeedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Replace home content even when customized');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $result = $this->heroHomeSeedService->seedHeroHomeIfUntouched($force);
        if ($result['seeded']) {
            $io->success($result['reason']);

            return Command::SUCCESS;
        }

        $io->warning($result['reason']);

        return Command::SUCCESS;
    }
}
