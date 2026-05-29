<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;

/**
 * Resolves the active plugin install mode.
 *
 * Three modes are supported:
 *
 *   - `development`: admin UI may run composer/npm directly. Requires
 *     `APP_ENV=dev` plus `SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true`.
 *     Intended for local development of plugin repos.
 *   - `managed` (default for production): admin UI creates plugin
 *     operations but never runs composer/npm. A CLI/CI worker runs the
 *     real install.
 *   - `trusted`: admin UI may run composer/npm directly on a
 *     single-server deployment. Requires
 *     `SELFHELP_ALLOW_WEB_PLUGIN_INSTALL=true` and a trust gate on the
 *     plugin source.
 *
 * The mode controls how `PluginInstaller` behaves; the resolver does
 * not itself execute installs. Production defaults to `managed`
 * because direct composer/npm from a web request is unsafe.
 */
final class InstallModeResolver
{
    public function __construct(
        private readonly string $appEnv,
        private readonly ?string $installModeEnv,
        private readonly bool $allowWebPluginInstall,
    ) {
    }

    public function resolve(): string
    {
        $mode = $this->installModeEnv ?: Plugin::INSTALL_MODE_MANAGED;
        if (!in_array($mode, [Plugin::INSTALL_MODE_DEVELOPMENT, Plugin::INSTALL_MODE_MANAGED, Plugin::INSTALL_MODE_TRUSTED], true)) {
            return Plugin::INSTALL_MODE_MANAGED;
        }

        // development mode only valid in dev environment.
        if ($mode === Plugin::INSTALL_MODE_DEVELOPMENT && $this->appEnv !== 'dev') {
            return Plugin::INSTALL_MODE_MANAGED;
        }

        // direct execution flag must also be true for dev/trusted modes.
        if (in_array($mode, [Plugin::INSTALL_MODE_DEVELOPMENT, Plugin::INSTALL_MODE_TRUSTED], true)
            && !$this->allowWebPluginInstall
        ) {
            return Plugin::INSTALL_MODE_MANAGED;
        }

        return $mode;
    }

    public function isDirectExecutionAllowed(): bool
    {
        $mode = $this->resolve();
        return $mode === Plugin::INSTALL_MODE_DEVELOPMENT
            || $mode === Plugin::INSTALL_MODE_TRUSTED;
    }
}
