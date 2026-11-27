<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

/*
 * One writer in its own process and its own database connection, for the
 * concurrency tests of the record version protocol (DataRevisionRaceTest).
 *
 *   php save_with_expected_version.php <table> <recordId> <expectedVersion> <field> <value>
 *
 * It saves through the real `DataService::saveData()` and prints one JSON line:
 * `{"ok":true,"version":N}` or `{"ok":false,"code":409,"data":{...}}`.
 */

use App\Exception\ServiceException;
use App\Kernel;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;

// Force the test environment BEFORE bootEnv (F266). PHPUnit only sets
// $_SERVER['APP_ENV']=test, which proc_open children do not inherit; without
// this, bootstrap loads .env.dev (dbname `test`) and Kernel('test') then
// appends `_test{TOKEN}` → a non-existent `test_test…` database. Clearing
// DATABASE_URL / Redis / Mercure lets .env.test.local apply (Dotenv does not
// override vars already set). TEST_TOKEN is left alone so the dbname suffix matches.
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');
foreach (['DATABASE_URL', 'REDIS_URL', 'MERCURE_URL', 'MERCURE_PUBLIC_URL', 'MERCURE_JWT_SECRET', 'MAILER_DSN'] as $envKey) {
    unset($_SERVER[$envKey], $_ENV[$envKey]);
    putenv($envKey);
}

require dirname(__DIR__, 2) . '/bootstrap.php';

/** @var list<string> $cliArgs */
$cliArgs = isset($_SERVER['argv']) && is_array($_SERVER['argv'])
    ? array_values(array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $_SERVER['argv']))
    : [];
[$table, $recordId, $expectedVersion, $field, $value] = array_map('strval', array_pad(array_slice($cliArgs, 1), 5, ''));

$kernel = new Kernel('test', filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof \Psr\Container\ContainerInterface);

$dataService = $container->get(DataService::class);
$revisions = $container->get(DataRevisionService::class);
assert($dataService instanceof DataService && $revisions instanceof DataRevisionService);

try {
    $dataService->saveData(
        $table,
        [$field => $value, 'trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED],
        LookupService::TRANSACTION_BY_BY_SYSTEM,
        ['record_id' => (int) $recordId],
        false,
        null,
        new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB, (int) $expectedVersion),
    );
    echo json_encode(['ok' => true, 'version' => $revisions->currentVersion((int) $recordId)]), "\n";
} catch (ServiceException $e) {
    echo json_encode(['ok' => false, 'code' => $e->getCode(), 'data' => $e->getData()]), "\n";
}
