<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use App\Plugin\Health\PluginHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'selfhelp:plugin:doctor',
    description: 'Run plugin health + drift checks; emit a JSON or pretty report.',
)]
final class PluginDoctorCommand extends Command
{
    public function __construct(
        private readonly PluginHealthService $healthService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the full report as JSON (for CI / monitoring).');
        $this->addOption('ci', null, InputOption::VALUE_NONE, 'Exit with non-zero status when any check is not "ok".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->healthService->runGlobalDoctor();

        if ($input->getOption('json')) {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $output->writeln($json !== false ? $json : '{}');
            return $this->resolveExit($input, $report);
        }

        $io->title('SelfHelp plugin doctor');
        $io->section('Site checks');
        $rows = [];
        $siteChecks = $report['siteChecks'] ?? [];
        if (is_iterable($siteChecks)) {
            foreach ($siteChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $rows[] = [
                    self::asText($check['name'] ?? ''),
                    self::asText($check['status'] ?? ''),
                    self::asText($check['message'] ?? ''),
                ];
            }
        }
        $io->table(['Check', 'Status', 'Message'], $rows);

        $io->section('Plugins');
        $pluginReports = $report['plugins'] ?? [];
        if (!is_iterable($pluginReports) || $pluginReports === []) {
            $io->info('No plugins installed.');
        } else {
            foreach ($pluginReports as $plugin) {
                if (!is_array($plugin)) {
                    continue;
                }
                $compat = $plugin['compatibility'] ?? null;
                $severity = is_array($compat) ? ($compat['severity'] ?? 'unknown') : 'unknown';
                $io->writeln(sprintf(
                    '<info>%s</info> v%s — enabled: %s — compatibility: %s',
                    self::asText($plugin['pluginId'] ?? ''),
                    self::asText($plugin['version'] ?? ''),
                    !empty($plugin['enabled']) ? 'yes' : 'no',
                    self::asText($severity),
                ));
            }
        }

        // Summary so warnings stay visible. Only `error`-severity
        // findings are fatal under `--ci`; warnings/info are surfaced
        // but never fail the build (so a fresh host exits 0).
        [$warnings, $errors] = self::tally($report);
        if ($errors > 0) {
            $io->error(sprintf('%d error(s), %d warning(s).', $errors, $warnings));
        } elseif ($warnings > 0) {
            $io->warning(sprintf('%d warning(s), 0 errors. Warnings do not fail --ci.', $warnings));
        } else {
            $io->success('All checks OK.');
        }

        return $this->resolveExit($input, $report);
    }

    /**
     * Coerce a loose report value to a printable string for table/console output.
     */
    private static function asText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string,mixed> $report
     */
    private function resolveExit(InputInterface $input, array $report): int
    {
        if (!$input->getOption('ci')) {
            return Command::SUCCESS;
        }
        return self::reportHasFatalError($report) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * CI contract: only `error`-severity findings are fatal. `warning`
     * and informational statuses are reported but never fail `--ci`, so
     * a fresh host (no plugins installed, infra probes skipped) exits 0.
     *
     * Kept as a pure static method so the exit-code contract can be unit
     * tested without booting the kernel or the (final) health service.
     *
     * @param array<string,mixed> $report
     */
    public static function reportHasFatalError(array $report): bool
    {
        $siteChecks = $report['siteChecks'] ?? [];
        if (is_iterable($siteChecks)) {
            foreach ($siteChecks as $check) {
                if (is_array($check) && ($check['status'] ?? 'ok') === 'error') {
                    return true;
                }
            }
        }
        $plugins = $report['plugins'] ?? [];
        if (is_iterable($plugins)) {
            foreach ($plugins as $plugin) {
                $compat = is_array($plugin) ? ($plugin['compatibility'] ?? null) : null;
                if (is_array($compat) && (($compat['severity'] ?? 'ok') === 'error')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $report
     * @return array{0:int,1:int} [warnings, errors]
     */
    private static function tally(array $report): array
    {
        $warnings = 0;
        $errors = 0;
        $siteChecks = $report['siteChecks'] ?? [];
        if (is_iterable($siteChecks)) {
            foreach ($siteChecks as $check) {
                $status = is_array($check) ? ($check['status'] ?? 'ok') : 'ok';
                if ($status === 'error') {
                    ++$errors;
                } elseif ($status === 'warning') {
                    ++$warnings;
                }
            }
        }
        $plugins = $report['plugins'] ?? [];
        if (is_iterable($plugins)) {
            foreach ($plugins as $plugin) {
                $compat = is_array($plugin) ? ($plugin['compatibility'] ?? null) : null;
                $severity = is_array($compat) ? ($compat['severity'] ?? 'ok') : 'ok';
                if ($severity === 'error') {
                    ++$errors;
                } elseif ($severity === 'warning') {
                    ++$warnings;
                }
            }
        }
        return [$warnings, $errors];
    }
}
