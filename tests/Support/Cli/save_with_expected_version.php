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

require dirname(__DIR__, 2) . '/bootstrap.php';

/** @var list<string> $cliArgs */
$cliArgs = isset($_SERVER['argv']) && is_array($_SERVER['argv'])
    ? array_values(array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $_SERVER['argv']))
    : [];
[$table, $recordId, $expectedVersion, $field, $value] = array_map('strval', array_pad(array_slice($cliArgs, 1), 5, ''));

$environment = $_SERVER['APP_ENV'] ?? 'test';
$kernel = new Kernel(is_string($environment) ? $environment : 'test', filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
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
