<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/**
 * Seed the performance datasets of the form lists into the ISOLATED performance
 * database (see FormListPerfDataset). It refuses to run in any other database.
 *
 *   export APP_ENV=test TEST_TOKEN=_form_list_perf REDIS_URL='redis://localhost:6379/6'
 *   php bin/console app:test:reset-db --force --env=test
 *   php tests/Support/Performance/seed-form-list-dataset.php
 *
 * The seed is deterministic and can be run again: a complete table is left alone.
 */

use App\Kernel;
use App\Tests\Support\Performance\FormListPerfDataset;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

(new Dotenv())->bootEnv($root . '/.env');

$token = $_SERVER['TEST_TOKEN'] ?? '';
if (($_SERVER['APP_ENV'] ?? '') !== 'test' || !is_string($token) || !str_contains($token, 'perf')) {
    fwrite(STDERR, "Set APP_ENV=test and TEST_TOKEN=_form_list_perf first.\n");
    exit(1);
}

$kernel = new Kernel('test', false);
$kernel->boot();

$testContainer = $kernel->getContainer()->get('test.service_container');
$connection = $testContainer instanceof ContainerInterface ? $testContainer->get(Connection::class) : null;
if (!$connection instanceof Connection) {
    fwrite(STDERR, "The test container is not available; run with APP_ENV=test.\n");
    exit(1);
}

(new FormListPerfDataset($connection))->seed(
    static function (string $message): void {
        fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $message . "\n");
    }
);
