<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Command;

use App\Repository\PageRepository;
use App\Service\CMS\NavigationSearchIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:navigation:rebuild-search-index',
    description: 'Rebuild the page_search_index projection for one page or every page.',
)]
class RebuildPageSearchIndexCommand extends Command
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly NavigationSearchIndexService $navigationSearchIndexService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('page-id', null, InputOption::VALUE_REQUIRED, 'Rebuild only this page id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageIdOption = $input->getOption('page-id');

        if (is_string($pageIdOption) && $pageIdOption !== '') {
            $pageId = (int) $pageIdOption;
            $this->navigationSearchIndexService->rebuildForPage($pageId);
            $io->success(sprintf('Rebuilt search index for page %d.', $pageId));

            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($this->pageRepository->findAll() as $page) {
            $pageId = $page->getId();
            if (!is_int($pageId)) {
                continue;
            }
            $this->navigationSearchIndexService->rebuildForPage($pageId);
            ++$count;
        }

        $io->success(sprintf('Rebuilt search index for %d page(s).', $count));

        return Command::SUCCESS;
    }
}
