<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Entity\ScheduledJob;
use App\Plugin\ScheduledJob\PluginScheduledJobHandlerInterface;

/**
 * QA double for the handler of one plugin job type of the platform contracts (C12, C13, C22, C25).
 * Registered once per job type in `config/packages/test/plugin_platform_services.yaml`, so it is
 * collected into the real {@see \App\Plugin\ScheduledJob\PluginScheduledJobRegistry}.
 *
 * What a run does, from the job's config:
 *  - `sleep_seconds` (int): sleeps that long before it ends;
 *  - `marker_file` (path): appends one line `start <job id> <unix time with fraction>` when it
 *    begins and `end <job id> <time>` when it ends, so a test that runs the job in another
 *    process can see how many runs there were and when they happened;
 *  - `fail` (true): ends with the failure result.
 *
 * Runs in the same process are also listed in {@see self::$calls}.
 */
final class QaPlatformJobHandler implements PluginScheduledJobHandlerInterface
{
    /** @var list<array{type: string, id: int, config: array<string, mixed>}> */
    public static array $calls = [];

    public function __construct(private readonly string $type)
    {
    }

    public function getSupportedJobType(): string
    {
        return $this->type;
    }

    public function execute(ScheduledJob $job, string $transactionBy): bool
    {
        $config = $job->getConfig() ?? [];
        $id = (int) $job->getId();
        self::$calls[] = ['type' => $this->type, 'id' => $id, 'config' => $config];

        $marker = $config['marker_file'] ?? null;
        $this->mark($marker, 'start', $id);

        $sleep = $config['sleep_seconds'] ?? 0;
        if (is_int($sleep) && $sleep > 0) {
            sleep($sleep);
        }

        $this->mark($marker, 'end', $id);

        return ($config['fail'] ?? false) !== true;
    }

    private function mark(mixed $file, string $what, int $jobId): void
    {
        if (!is_string($file) || $file === '') {
            return;
        }

        file_put_contents($file, sprintf("%s %d %.3f\n", $what, $jobId, microtime(true)), FILE_APPEND | LOCK_EX);
    }
}
