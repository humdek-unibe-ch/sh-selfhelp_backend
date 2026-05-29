<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\PackageManager;

use Composer\Autoload\ClassLoader;

/**
 * Boot-time helper that conditionally loads the plugin Composer root's
 * autoloader and registers it on the SPL chain BEHIND the host loader.
 *
 * Called from `public/index.php`, `bin/console`, and
 * `tests/bootstrap.php` immediately after the host's
 * `vendor/autoload_runtime.php` (or `vendor/autoload.php`) so plugin
 * bundle classes become resolvable for the rest of the boot path
 * (`config/bundles.php` → `config/selfhelp_plugin_bundles.php` →
 * Symfony `Kernel::registerBundles()`).
 *
 * The plugin loader is APPENDED (`prepend=false`). On namespace
 * collision the host loader resolves first; the plugin loader only
 * gets a chance for classes the host could not autoload. This protects
 * against accidentally double-loading framework classes from two
 * different vendor trees if a misconfigured manifest sneaks past the
 * `provide` policy in {@see PluginComposerRoot}. See the dependency
 * policy section of `docs/plugins/architecture.md`.
 *
 * Idempotent: safe to call from every entry point. Subsequent calls
 * after the first registration are no-ops via
 * {@see PluginAutoloaderRegistry::get()}.
 */
final class PluginAutoloaderBootstrap
{
    /**
     * Loads the plugin autoload.php (if it exists), re-registers the
     * returned `ClassLoader` with prepend=false, and stashes it in
     * {@see PluginAutoloaderRegistry}. Returns the loader on success
     * or null when no plugin Composer root has been initialised yet
     * (a fresh install with no plugins).
     */
    public static function register(string $projectDir): ?ClassLoader
    {
        if (PluginAutoloaderRegistry::get() !== null) {
            return PluginAutoloaderRegistry::get();
        }

        $autoloadPath = $projectDir
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'plugin-composer'
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoloadPath)) {
            return null;
        }

        // `vendor/autoload.php` calls `register()` on the loader for
        // us (with prepend=true by default), which would put plugin
        // classes AHEAD of the host's. Unregister and re-register at
        // the tail so host classes win on namespace collision. This
        // is the documented Composer way (see
        // https://github.com/composer/composer/blob/main/src/Composer/Autoload/ClassLoader.php).
        $loader = require $autoloadPath;
        if (!$loader instanceof ClassLoader) {
            return null;
        }
        $loader->unregister();
        $loader->register(false);

        PluginAutoloaderRegistry::set($loader);
        return $loader;
    }
}
