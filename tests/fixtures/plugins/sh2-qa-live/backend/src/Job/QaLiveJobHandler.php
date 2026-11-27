<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace Humdek\QaLiveBundle\Job;

use App\Entity\ScheduledJob;
use App\Plugin\ScheduledJob\PluginScheduledJobHandlerInterface;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use Humdek\QaLiveBundle\QaLiveCatalogue;

final class QaLiveJobHandler implements PluginScheduledJobHandlerInterface
{
    private const DEFAULT_TABLE = 'qa_live_job_results';

    public function __construct(private readonly DataService $data)
    {
    }

    public function getSupportedJobType(): string
    {
        return QaLiveCatalogue::JOB_TYPE;
    }

    public function execute(ScheduledJob $job, string $transactionBy): bool
    {
        $config = $job->getConfig() ?? [];
        // Action-planned jobs nest the schema under `plugin_config` (C12);
        // `PluginJobQueue::queueNow` stores the plugin keys at the top level.
        $plugin = is_array($config['plugin_config'] ?? null) ? $config['plugin_config'] : $config;

        $table = is_string($plugin['result_table'] ?? null) && $plugin['result_table'] !== ''
            ? (string) $plugin['result_table']
            : self::DEFAULT_TABLE;
        if (!str_starts_with($table, 'qa_live_')) {
            return false;
        }

        $note = is_string($plugin['note'] ?? null) ? (string) $plugin['note'] : '';
        if ($note === '') {
            $note = 'qa live job';
        }

        $row = ['note' => $note];
        $ownerId = $job->getUser()?->getId();
        if ($ownerId !== null && $ownerId > 0) {
            // Non-interactive writes may set the owner (rule 4.4); the page reads current_user.
            $row['id_users'] = $ownerId;
        }

        $context = new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB);
        $written = $this->data->saveData(
            $table,
            $row,
            LookupService::TRANSACTION_BY_BY_CRON_JOB,
            ownEntriesOnly: false,
            context: $context,
        );

        return $written !== false;
    }
}