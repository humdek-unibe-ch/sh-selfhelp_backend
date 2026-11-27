<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\Access\AccessCatalogueExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes (or with --check verifies) the generated access files (F239, rule 4.14):
 *
 * - `docs/reference/access/api-routes.json`, from the core route rows;
 * - `docs/reference/permissions.md`, from the same rows plus the frontend's
 *   `docs/reference/admin-screens.json`.
 *
 * Fails when a core permission guards no route and opens no screen, and when
 * the screens file is missing. Usage:
 *
 *   php bin/console app:access:export [--check] [--frontend-dir=<path>]
 */
#[AsCommand(
    name: 'app:access:export',
    description: 'Write or check the generated route table and permission catalogue.'
)]
final class AccessExportCommand extends Command
{
    public function __construct(
        private readonly AccessCatalogueExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing; exit 1 when a file is stale or a permission guards nothing.')
            ->addOption('frontend-dir', null, InputOption::VALUE_REQUIRED, 'Frontend checkout holding docs/reference/admin-screens.json (default: $SELFHELP_FRONTEND_DIR, else ../sh-selfhelp_frontend).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $check = (bool) $input->getOption('check');
        $frontendOption = $input->getOption('frontend-dir');
        $frontendDir = $this->exporter->resolveFrontendDir(is_string($frontendOption) ? $frontendOption : null);
        $problems = [];

        $table = $this->exporter->readRouteTable();
        $routesJson = $this->exporter->renderRouteTable($table);
        $this->handle(AccessCatalogueExporter::ROUTES_FILE, $routesJson, $check, $io, $problems);

        try {
            $screens = $this->exporter->loadScreens($frontendDir);
        } catch (\RuntimeException $e) {
            // The route table is still handled above; the catalogue and the
            // rule 4.14 check need the screens file, so this is a failure.
            $screens = null;
            $problems[] = $e->getMessage();
        }

        if ($screens !== null) {
            $this->handle(
                AccessCatalogueExporter::CATALOGUE_FILE,
                $this->exporter->renderCatalogue($table, $screens),
                $check,
                $io,
                $problems
            );

            foreach ($this->exporter->findUnguardedPermissions($table, $screens) as $name) {
                $problems[] = sprintf('Permission %s guards no route and opens no screen (rule 4.14).', $name);
            }
        }

        if ($problems !== []) {
            $io->error($problems);

            return Command::FAILURE;
        }
        $io->success($check ? 'Generated access files are up to date.' : 'Generated access files written.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $problems
     */
    private function handle(string $file, string $content, bool $check, SymfonyStyle $io, array &$problems): void
    {
        if ($check) {
            if (!$this->exporter->isFresh($file, $content)) {
                $problems[] = sprintf('%s is stale: run php bin/console app:access:export', $file);
            }

            return;
        }
        $this->exporter->write($file, $content);
        $io->writeln(sprintf('Wrote %s', $file));
    }
}
