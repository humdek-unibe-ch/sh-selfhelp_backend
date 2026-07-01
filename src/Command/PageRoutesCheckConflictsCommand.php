<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Command;

use App\Routing\RouteConflictValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Out-of-band guard for the DB-driven public routing contract (issue #30).
 *
 * {@see RouteConflictValidator} blocks duplicate/ambiguous `page_routes` at
 * write time (admin editor, importer, wizard), but a raw SQL edit, a restored
 * backup, or a partial migration could still leave the active route set
 * self-inconsistent. This command scans EVERY active route against every other
 * via {@see RouteConflictValidator::findAllConflicts()} and exits non-zero when
 * any duplicate or same-shape ambiguity is found, so it can gate
 * `composer validate-db` / CI and be run after a deploy.
 *
 * Usage:
 *   php bin/console app:page-routes:check-conflicts
 */
#[AsCommand(
    name: 'app:page-routes:check-conflicts',
    description: 'Fail if any active page_routes are duplicate or ambiguous (same path shape).'
)]
class PageRoutesCheckConflictsCommand extends Command
{
    public function __construct(
        private readonly RouteConflictValidator $conflictValidator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Page route conflict check');

        $conflicts = $this->conflictValidator->findAllConflicts();

        if ($conflicts === []) {
            $io->success('No duplicate or ambiguous active page routes found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($conflicts as $conflict) {
            $rows[] = [
                $conflict['type'],
                $conflict['path_pattern'],
                $conflict['conflicting_pattern'],
                $conflict['conflicting_keyword'] ?? '',
                $conflict['message'],
            ];
        }

        $io->table(['Type', 'Pattern', 'Conflicts with', 'Owner', 'Message'], $rows);
        $io->error(sprintf('Found %d page route conflict(s). Resolve them before deploying.', count($conflicts)));

        return Command::FAILURE;
    }
}
