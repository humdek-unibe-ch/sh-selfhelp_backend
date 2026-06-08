<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for production Docker images (`composer install --no-dev`).
 *
 * Every bundle registered in `config/bundles.php` for the `all` or `prod`
 * environment must be backed by a Composer package in the production
 * `packages` section of `composer.lock` (not `packages-dev`). Otherwise the app
 * boots in dev/test (where dev deps are present) but crashes in a production
 * image with "Class ... not found" — exactly what happened when
 * `symfony/twig-bundle` was only available transitively via the dev-only
 * web-profiler while `TwigBundle` was registered for all environments.
 */
final class ProductionBundlesTest extends TestCase
{
    /**
     * Bundles loaded in the production environment, taken straight from
     * `config/bundles.php` (so it can never drift from the real registration).
     *
     * @return iterable<string, array{0: class-string}>
     */
    public static function productionBundleProvider(): iterable
    {
        $root = \dirname(__DIR__, 3);
        /** @var array<class-string, array<string, bool>> $bundles */
        $bundles = require $root . '/config/bundles.php';

        foreach ($bundles as $class => $environments) {
            $loadedInProd = ($environments['all'] ?? false) === true || ($environments['prod'] ?? false) === true;
            if ($loadedInProd) {
                yield $class => [$class];
            }
        }
    }

    #[DataProvider('productionBundleProvider')]
    public function testProductionBundlePackageIsNotDevOnly(string $bundleClass): void
    {
        self::assertTrue(
            class_exists($bundleClass),
            sprintf('Bundle %s is registered for production but is not autoloadable.', $bundleClass)
        );

        $file = (new \ReflectionClass($bundleClass))->getFileName();
        self::assertIsString($file);

        $root = str_replace('\\', '/', \dirname(__DIR__, 3));
        $mainVendor = $root . '/vendor/';
        $normalized = str_replace('\\', '/', $file);

        // Only third-party packages under the host vendor/ are relevant here.
        // App-owned bundles and plugin-composer bundles are out of scope.
        if (!str_starts_with($normalized, $mainVendor)) {
            $this->addToAssertionCount(1);

            return;
        }

        $relative = substr($normalized, \strlen($mainVendor));
        $parts = explode('/', $relative);
        self::assertGreaterThanOrEqual(2, \count($parts), "Cannot derive a package name from {$file}.");
        $package = strtolower($parts[0] . '/' . $parts[1]);

        [$prod, $dev] = self::lockPackages();

        self::assertContains(
            $package,
            $prod,
            sprintf(
                'Bundle %s is loaded in production (config/bundles.php) but its package "%s" is %s. '
                . 'Add it to composer.json "require" so --no-dev production images can boot.',
                $bundleClass,
                $package,
                \in_array($package, $dev, true) ? 'only a require-dev dependency' : 'absent from composer.lock production packages'
            )
        );
    }

    /**
     * Explicit guard for the concrete bug this test was written for: the global
     * `config/packages/twig.yaml` + the all-environment `TwigBundle` registration
     * mean Twig must be a production dependency.
     */
    public function testTwigIsAProductionDependency(): void
    {
        [$prod] = self::lockPackages();
        self::assertContains('symfony/twig-bundle', $prod);
        self::assertContains('twig/twig', $prod);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function lockPackages(): array
    {
        $root = \dirname(__DIR__, 3);
        $raw = file_get_contents($root . '/composer.lock');
        self::assertIsString($raw, 'composer.lock must be readable.');
        /** @var array{packages?: list<array{name?: string}>, packages-dev?: list<array{name?: string}>} $lock */
        $lock = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $names = static function (array $packages): array {
            $out = [];
            foreach ($packages as $package) {
                if (isset($package['name']) && \is_string($package['name'])) {
                    $out[] = strtolower($package['name']);
                }
            }

            return $out;
        };

        return [$names($lock['packages'] ?? []), $names($lock['packages-dev'] ?? [])];
    }
}
