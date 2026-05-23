<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


use App\Kernel;

// Plugin install/uninstall runs `composer require|remove` in-process via
// the Messenger sync transport (dev) and serialises the entire request
// profile via the Symfony profiler. Both spike memory well past the
// PHP default (128M) on Windows. Bump the limit here so the dev path
// for plugin operations does not fatal in FileProfilerStorage::write().
if (\PHP_SAPI !== 'cli') {
    @ini_set('memory_limit', '1024M');
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Plugin Composer root lives under `var/plugin-composer/` and is
// resolved by a SECONDARY ClassLoader appended to the SPL chain. This
// keeps host `composer.json` / `composer.lock` / `vendor/` untouched
// by plugin install/update/uninstall. See
// `App\Plugin\PackageManager\PluginAutoloaderBootstrap` and
// `docs/plugins/architecture.md`.
\App\Plugin\PackageManager\PluginAutoloaderBootstrap::register(dirname(__DIR__));

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
