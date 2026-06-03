<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


$coreBundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle::class => ['all' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Nelmio\CorsBundle\NelmioCorsBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\MercureBundle\MercureBundle::class => ['all' => true],
    // Wraps every test in a transaction that is rolled back on tearDown so
    // the seeded QA baseline (loaded once by `app:test:reset-db`) is the
    // stable starting point for every test. See config/packages/test/doctrine.yaml.
    DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
];

// Plugin bundles are loaded from a generated file written atomically
// by the plugin installer/uninstaller. The file is missing on a clean
// install and on installs that have no backend-bundle plugins. The
// emergency safe-mode env (SELFHELP_DISABLE_PLUGINS=true) short-circuits
// the include so a broken plugin bundle can never break Symfony boot.
// The persistent safe-mode flag (var/plugin_safe_mode.lock written by
// `selfhelp:plugin:safe-mode --enable`) is also honored so ops do not
// have to edit `.env` to recover from a broken plugin.
$pluginBundles = [];
$safeModeEnv = filter_var($_ENV['SELFHELP_DISABLE_PLUGINS'] ?? $_SERVER['SELFHELP_DISABLE_PLUGINS'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$safeModeFlag = is_file(dirname(__DIR__) . '/var/plugin_safe_mode.lock');
if (!$safeModeEnv && !$safeModeFlag) {
    $generated = __DIR__ . '/selfhelp_plugin_bundles.php';
    if (is_file($generated)) {
        $loaded = require $generated;
        if (is_array($loaded)) {
            $pluginBundles = $loaded;
        }
    }
}

return $coreBundles + $pluginBundles;
