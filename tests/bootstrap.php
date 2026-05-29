<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Plugin Composer root lives under `var/plugin-composer/` and is
// resolved by a SECONDARY ClassLoader appended to the SPL chain. See
// `App\Plugin\PackageManager\PluginAutoloaderBootstrap`.
\App\Plugin\PackageManager\PluginAutoloaderBootstrap::register(dirname(__DIR__));

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
