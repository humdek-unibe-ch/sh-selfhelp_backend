<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for admin plugin routes.
 *
 * The Symfony backend wires plugin admin routes via DB rows declared
 * in `migrations/Version20260522062459.php` (initial routes) and
 * `migrations/Version20260523141331.php` (cancel endpoint). If a
 * controller method referenced by one of those rows is removed (as
 * happened to `listOperations`, `getOperation`, and `rollback` during
 * the refactor), the route exists but every call to it crashes with
 * a missing-method 500.
 *
 * This test scans both migrations for the canonical
 * `App\Controller\Api\V1\Admin\Plugin\Admin*Controller::method`
 * strings and asserts each `method` exists on the matching class.
 */
final class PluginAdminRoutesConsistencyTest extends TestCase
{
    private const MIGRATIONS = [
        __DIR__ . '/../../../../../../migrations/Version20260522062459.php',
        __DIR__ . '/../../../../../../migrations/Version20260523141331.php',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRouteCallables(): iterable
    {
        $seen = [];
        foreach (self::MIGRATIONS as $migrationPath) {
            if (!is_file($migrationPath)) {
                continue;
            }
            $source = (string) file_get_contents($migrationPath);
            // Match `Admin<Whatever>Controller::method` anywhere in
            // the migration; we don't anchor on the full namespace
            // because two migrations write it slightly differently
            // (the routes-seeding migration uses
            // `App\\Controller\\…` strings, the cancel migration
            // uses a heredoc with `App\Controller\…`).
            $pattern = '/(Admin[A-Za-z0-9_]*Controller)::([A-Za-z0-9_]+)/';
            if (!preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $class = $match[1];
                $method = $match[2];
                $key = $class . '::' . $method;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                yield $key => ['App\\Controller\\Api\\V1\\Admin\\Plugin\\' . $class, $method];
            }
        }
    }

    #[DataProvider('provideRouteCallables')]
    public function testEveryPluginAdminRouteResolvesToAnExistingMethod(string $fqcn, string $method): void
    {
        self::assertTrue(
            class_exists($fqcn),
            sprintf('Controller class %s referenced by plugin admin routes does not exist.', $fqcn),
        );
        self::assertTrue(
            method_exists($fqcn, $method),
            sprintf(
                'Controller %s is missing method %s referenced by plugin admin routes. ' .
                'Either restore the method or delete the route row from the matching migration.',
                $fqcn,
                $method,
            ),
        );
    }
}
