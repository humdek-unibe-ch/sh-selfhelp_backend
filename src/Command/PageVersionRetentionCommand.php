<?php

namespace App\Command;

use App\Repository\PageRepository;
use App\Service\CMS\Admin\PageVersionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * PageVersionRetentionCommand
 * 
 * Console command to apply retention policy for page versions.
 * Keeps only the last N versions for each page, protecting published versions.
 * 
 * Usage:
 *   php bin/console app:page-version:retention --keep=10
 *   php bin/console app:page-version:retention --keep=20 --page=5
 */
#[AsCommand(
    name: 'app:page-version:retention',
    description: 'Apply retention policy to page versions'
)]
class PageVersionRetentionCommand extends Command
{
    public function __construct(
        private readonly PageVersionService $pageVersionService,
        private readonly PageRepository $pageRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'keep',
                'k',
                InputOption::VALUE_REQUIRED,
                'Number of versions to keep per page',
                '10'
            )
            ->addOption(
                'page',
                'p',
                InputOption::VALUE_REQUIRED,
                'Apply retention to specific page ID only',
                null
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be deleted without actually deleting'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $keepCount = (int) $input->getOption('keep');
        $pageId = $input->getOption('page') ? (int) $input->getOption('page') : null;
        $dryRun = $input->getOption('dry-run');

        $io->title('Page Version Retention Policy');
        $io->info(sprintf('Keeping last %d versions per page', $keepCount));

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No versions will be deleted');
        }

        // Get pages to process
        $pages = $pageId ? [$this->pageRepository->find($pageId)] : $this->pageRepository->findAll();

        if (empty($pages)) {
            $io->error('No pages found');
            return Command::FAILURE;
        }

        $totalDeleted = 0;
        $pagesProcessed = 0;

        $io->section('Processing pages...');
        $progressBar = $io->createProgressBar(count($pages));

        foreach ($pages as $page) {
            if (!$page) {
                continue;
            }

            try {
                if (!$dryRun) {
                    $deletedCount = $this->pageVersionService->applyRetentionPolicy($page->getId(), $keepCount);
                    $totalDeleted += $deletedCount;
                    
                    if ($deletedCount > 0) {
                        $io->writeln(sprintf(
                            "\nPage '%s' (ID: %d): Deleted %d old version(s)",
                            $page->getKeyword(),
                            $page->getId(),
                            $deletedCount
                        ));
                    }
                } else {
                    $io->writeln(sprintf(
                        "\n[DRY RUN] Page '%s' (ID: %d): Would process retention",
                        $page->getKeyword(),
                        $page->getId()
                    ));
                }

                $pagesProcessed++;
            } catch (\Exception $e) {
                $io->error(sprintf(
                    "Error processing page %d: %s",
                    $page->getId(),
                    $e->getMessage()
                ));
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->success(sprintf(
            'Retention policy applied successfully%s',
            $dryRun ? ' (DRY RUN)' : ''
        ));
        
        $io->table(
            ['Metric', 'Count'],
            [
                ['Pages Processed', $pagesProcessed],
                ['Versions Deleted', $dryRun ? 'N/A (dry run)' : $totalDeleted],
                ['Versions Kept per Page', $keepCount]
            ]
        );

        return Command::SUCCESS;
    }
}

