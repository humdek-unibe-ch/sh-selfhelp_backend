<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Backup;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default implementation: logs a warning + returns a recommended
 * `mysqldump` command. Real backups must be configured by the
 * operator (see docs/plugins/installation.md).
 */
final class NoopPluginBackupHook implements PluginBackupHookInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function beforeDestructive(string $pluginId, string $operationType, array $affectedTables): array
    {
        $recommendation = $affectedTables === []
            ? sprintf('Review plugin "%s" %s impact before proceeding (no specific tables identified).', $pluginId, $operationType)
            : sprintf(
                'No automated backup was taken. Recommended manual backup: mysqldump <db> %s > %s_%s_%s.sql',
                implode(' ', $affectedTables),
                $pluginId,
                $operationType,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His')
            );

        $this->logger->warning('Plugin operation reached destructive step without configured backup hook', [
            'plugin_id' => $pluginId,
            'operation' => $operationType,
            'affected_tables' => $affectedTables,
        ]);

        return [
            'performed' => false,
            'reference' => null,
            'recommendation' => $recommendation,
        ];
    }
}
