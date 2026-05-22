<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Plugin\Bundle\PluginBundlesFileWriter;

/**
 * Persistent safe-mode toggle.
 *
 * When safe mode is on:
 *   - `var/plugin_safe_mode.lock` exists,
 *   - `config/bundles.php` short-circuits and returns only core
 *     bundles (the env-based `SELFHELP_DISABLE_PLUGINS` check is the
 *     primary gate; the persistent file lets ops keep the gate across
 *     restarts without editing `.env`).
 *
 * The CLI command `selfhelp:plugin:safe-mode --enable` writes the
 * file; `--disable` removes it.
 */
final class PluginSafeMode
{
    public function __construct(
        private readonly string $projectDir,
        private readonly PluginBundlesFileWriter $bundlesWriter,
    ) {
    }

    public function isEnabled(): bool
    {
        return is_file($this->safeModePath());
    }

    public function enable(): void
    {
        $path = $this->safeModePath();
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, sprintf("safe-mode enabled at %s\n", (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM)));
    }

    public function disable(): void
    {
        $path = $this->safeModePath();
        if (is_file($path)) {
            @unlink($path);
        }
        $this->bundlesWriter->regenerate();
    }

    private function safeModePath(): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'plugin_safe_mode.lock';
    }
}
