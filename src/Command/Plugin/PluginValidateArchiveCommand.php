<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Archive\PluginArchiveInspectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `selfhelp:plugin:validate-archive <path>` — run the same pipeline
 * as `POST /admin/plugins/inspect-archive` against a local `.shplugin`
 * file. Useful in CI before publishing a plugin release to confirm
 * that signature + canonical payload + SHA-256 sums + manifest
 * compatibility all line up against the host's trusted-keys env.
 *
 * Exit codes:
 *   0 — archive validates cleanly (signatureStatus=verified).
 *   1 — validation reported errors (signature invalid / unsigned /
 *       capability violation / compatibility blocking).
 */
#[AsCommand(
    name: 'selfhelp:plugin:validate-archive',
    description: 'Validate a .shplugin archive (signature + checksums + manifest) without installing it.',
)]
final class PluginValidateArchiveCommand extends Command
{
    public function __construct(
        private readonly PluginArchiveInspectionService $inspection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Absolute or relative path to the .shplugin archive.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the inspection result as JSON instead of human-readable text.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('archive');
        if (!is_file($path)) {
            $io->error(sprintf('Archive "%s" does not exist.', $path));
            return Command::FAILURE;
        }

        $upload = new UploadedFile($path, basename($path), null, null, true);
        $result = $this->inspection->inspect($upload);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $manifest = $result['manifest'] ?? [];
        $pluginId = isset($manifest['id']) && is_string($manifest['id']) ? $manifest['id'] : '(unknown)';
        $version = isset($manifest['version']) && is_string($manifest['version']) ? $manifest['version'] : '(unknown)';

        $io->section(sprintf('Plugin: %s @ %s', $pluginId, $version));
        $io->writeln(sprintf('Signature status: <info>%s</info>', $result['signatureStatus']));
        $io->writeln(sprintf('Capabilities: %s', $result['capabilities'] === [] ? '(none)' : implode(', ', $result['capabilities'])));

        $compat = $result['compatibility'] ?? null;
        if (is_array($compat)) {
            $severity = isset($compat['severity']) && is_string($compat['severity']) ? $compat['severity'] : 'unknown';
            $io->writeln(sprintf('Compatibility: severity=%s', $severity));
            $reasons = $compat['reasons'] ?? [];
            if (is_array($reasons)) {
                foreach ($reasons as $reason) {
                    if (is_string($reason)) {
                        $io->writeln('  · ' . $reason);
                    }
                }
            }
        }

        if ($result['warnings'] !== []) {
            $io->warning(['Warnings:', ...$result['warnings']]);
        }

        if ($result['errors'] !== []) {
            $io->error(['Validation failed:', ...$result['errors']]);
            return Command::FAILURE;
        }

        $io->success('Archive validates cleanly.');
        return Command::SUCCESS;
    }
}
