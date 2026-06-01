<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Command\Plugin;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'selfhelp:plugin:enable',
    description: 'Enable an installed plugin.',
)]
final class PluginEnableCommand extends PluginEnableDisableCommand
{
    protected function shouldDisable(): bool
    {
        return false;
    }
}
