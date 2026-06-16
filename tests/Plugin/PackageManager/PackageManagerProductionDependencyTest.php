<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\PackageManager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression guard for production Docker images (`composer install --no-dev`).
 *
 * `PackageManagerRunner` launches every `composer require`/`remove` through
 * `Symfony\Component\Process\Process`. Install and uninstall run in the
 * Messenger worker, but a plugin **purge** runs synchronously inside the
 * HTTP request (`AdminPluginController::purge()` ->
 * `PluginAdminService::purge()` -> `PluginPurger::purge()` ->
 * `PackageManagerRunner::removeComposerPackage()`).
 *
 * `symfony/process` used to be present only transitively, pulled in by the
 * dev-only `symfony/maker-bundle` / `symfony/web-profiler-bundle`. In a
 * production image (`--no-dev`) those dev packages — and therefore
 * `symfony/process` — are absent, so the purge endpoint crashed with
 *   `Class "Symfony\Component\Process\Process" not found`
 * (HTTP 500) while install/uninstall kept working in dev/test where the dev
 * dependencies are installed.
 *
 * This mirrors {@see \App\Tests\Unit\Config\ProductionBundlesTest} (the
 * twig-bundle dev-only regression) for the runtime component the plugin
 * lifecycle depends on directly.
 */
final class PackageManagerProductionDependencyTest extends TestCase
{
    public function testProcessComponentIsDeclaredAsADirectProductionRequire(): void
    {
        $composerJson = self::readJson(self::projectRoot() . '/composer.json');

        /** @var array<string, string> $require */
        $require = \is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];

        self::assertArrayHasKey(
            'symfony/process',
            $require,
            'symfony/process must be a direct entry in composer.json "require". '
            . 'PackageManagerRunner uses Symfony\Component\Process\Process directly and the plugin '
            . 'purge path runs it synchronously in the web request, so a --no-dev production image '
            . 'must ship the package.'
        );
    }

    public function testProcessComponentIsLockedInTheProductionPackagesSection(): void
    {
        $lock = self::readJson(self::projectRoot() . '/composer.lock');

        $prod = self::lockPackageNames($lock['packages'] ?? null);
        $dev = self::lockPackageNames($lock['packages-dev'] ?? null);

        self::assertContains(
            'symfony/process',
            $prod,
            \in_array('symfony/process', $dev, true)
                ? 'symfony/process is locked only as a require-dev dependency; it must be in the '
                    . 'production "packages" section so plugin purge works in a --no-dev image.'
                : 'symfony/process is missing from composer.lock production packages.'
        );
    }

    public function testProcessClassIsAvailableToTheRunner(): void
    {
        self::assertTrue(
            class_exists(Process::class),
            'Symfony\Component\Process\Process must be autoloadable for PackageManagerRunner.'
        );
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, sprintf('%s must be readable.', $path));
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function lockPackageNames(mixed $packages): array
    {
        if (!\is_array($packages)) {
            return [];
        }
        $names = [];
        foreach ($packages as $package) {
            if (\is_array($package) && isset($package['name']) && \is_string($package['name'])) {
                $names[] = strtolower($package['name']);
            }
        }

        return $names;
    }
}
