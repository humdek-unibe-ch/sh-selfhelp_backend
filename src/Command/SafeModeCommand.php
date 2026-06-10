<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command;

use App\Plugin\Service\PluginAdminService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Canonical system safe-mode toggle.
 *
 * Safe mode boots SelfHelp with core bundles only — no plugin bundles, event
 * subscribers, scheduled-job handlers or routes load. It is the recovery switch
 * for a broken/incompatible plugin after an update.
 *
 * This is a thin alias over the existing plugin safe-mode mechanism
 * ({@see PluginAdminService} -> {@see \App\Plugin\Lifecycle\PluginSafeMode}),
 * which writes `var/plugin_safe_mode.lock` so the gate survives restarts. The
 * `SELFHELP_DISABLE_PLUGINS` env hard switch is the primary gate and always
 * wins: `--disable` cannot clear an env-forced safe mode (the command reports
 * that). The older `selfhelp:plugin:safe-mode` name remains as a back-compat
 * alias for the same behaviour.
 */
#[AsCommand(
    name: 'selfhelp:safe-mode',
    description: 'Enable/disable system safe mode (boots SelfHelp with core bundles only — no plugins).',
)]
final class SafeModeCommand extends Command
{
    public function __construct(
        private readonly PluginAdminService $pluginAdminService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Enable safe mode (creates var/plugin_safe_mode.lock).');
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Disable safe mode and regenerate the plugin bundles file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');
        $forcedByEnv = $this->isForcedByEnv();

        if (!$enable && !$disable) {
            $io->info(sprintf(
                'Safe mode is currently %s%s.',
                ($this->pluginAdminService->isSafeModeOn() || $forcedByEnv) ? 'ENABLED' : 'disabled',
                $forcedByEnv ? ' (forced by SELFHELP_DISABLE_PLUGINS)' : '',
            ));

            return Command::SUCCESS;
        }
        if ($enable && $disable) {
            $io->error('Pass only one of --enable / --disable.');

            return Command::FAILURE;
        }

        if ($enable) {
            $this->pluginAdminService->safeModeEnable();
            $io->success('System safe mode enabled. Plugins will not load on next boot.');

            return Command::SUCCESS;
        }

        $this->pluginAdminService->safeModeDisable();
        if ($forcedByEnv) {
            $io->warning('Safe-mode lock removed, but SELFHELP_DISABLE_PLUGINS still forces safe mode on. Clear it in the instance .env.');

            return Command::SUCCESS;
        }
        $io->success('System safe mode disabled. Plugin bundles file regenerated.');

        return Command::SUCCESS;
    }

    /**
     * Mirror the env gate in `config/bundles.php` so the reported state matches
     * what actually loads at boot.
     */
    private function isForcedByEnv(): bool
    {
        $raw = $_ENV['SELFHELP_DISABLE_PLUGINS'] ?? $_SERVER['SELFHELP_DISABLE_PLUGINS'] ?? 'false';

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
