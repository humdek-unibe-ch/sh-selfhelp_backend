<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Event;

use App\Plugin\Event\Domain\PluginDomainEventListenerInterface;
use App\Plugin\Event\Domain\RecordSavedEvent;
use App\Plugin\ScheduledJob\PluginJobQueueInterface;
use Doctrine\DBAL\Connection;
use Humdek\QaLiveBundle\QaLiveCatalogue;

/**
 * C24: queue a QA job after a record is saved; never write data directly.
 */
final class QaLiveRecordSavedListener implements PluginDomainEventListenerInterface
{
    public function __construct(
        private readonly PluginJobQueueInterface $queue,
        private readonly Connection $connection,
    ) {
    }

    public function pluginId(): string
    {
        return QaLiveCatalogue::PLUGIN_ID;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof RecordSavedEvent) {
            return;
        }

        $tableName = $this->connection->fetchOne(
            'SELECT name FROM data_tables WHERE id = ?',
            [$event->tableId],
        );
        if (!is_string($tableName) || !str_starts_with($tableName, 'qa_live_')) {
            return;
        }

        $this->queue->queueNow(
            QaLiveCatalogue::PLUGIN_ID,
            QaLiveCatalogue::JOB_TYPE,
            [
                'result_table' => 'qa_live_job_results',
                'note' => 'queued from record.saved',
            ],
            $event->ownerUserId,
        );
    }
}