<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\PackageManager;

use Composer\Autoload\ClassLoader;

/**
 * Static handle to the secondary `Composer\Autoload\ClassLoader` that
 * resolves plugin-only packages out of `var/plugin-composer/vendor/`.
 *
 * The host's primary autoloader is registered in
 * `vendor/autoload_runtime.php` and stays untouched. The plugin loader
 * is registered AFTER the host loader (prepend=false) so that on
 * namespace collision the host's classes win — the plugin loader only
 * resolves classes the host loader could not. See the boot snippets in
 * `public/index.php` / `bin/console` / `tests/bootstrap.php` and the
 * dependency-policy section in `docs/plugins/installation.md`.
 *
 * Why a static registry instead of a service: the loader must be
 * available BEFORE the Symfony container boots (the container itself
 * uses `class_exists` on plugin bundle classes during boot). A static
 * field is the smallest moving part that satisfies that constraint.
 *
 * `PackageManagerRunner::refreshComposerAutoloader()` reads from this
 * registry after a successful `composer require` to merge the freshly
 * regenerated PSR-4 / classmap maps into the live loader instance, so
 * the in-memory worker can resolve newly installed plugin classes
 * without restarting.
 */
final class PluginAutoloaderRegistry
{
    private static ?ClassLoader $loader = null;

    public static function set(ClassLoader $loader): void
    {
        self::$loader = $loader;
    }

    public static function get(): ?ClassLoader
    {
        return self::$loader;
    }
}
