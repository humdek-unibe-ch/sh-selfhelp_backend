<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Health;

/**
 * Plugins that ship a health endpoint implement this interface and
 * register the service with the tag `selfhelp.plugin.health_check`.
 *
 * The host's `PluginHealthService` aggregates results from every
 * registered check and the doctor command consults them at the end of
 * the global report.
 */
interface PluginHealthCheckInterface
{
    /**
     * Identifies which plugin this check belongs to.
     */
    public function getPluginId(): string;

    /**
     * Run the check. Implementations should be fast (< 1 s) and not
     * raise on infrastructure issues; instead return them as failed
     * subchecks.
     *
     * @return array{
     *   status: 'ok'|'warning'|'failed',
     *   subchecks: list<array{
     *     name: string,
     *     status: 'ok'|'warning'|'failed',
     *     message: string,
     *     metadata?: array<string,mixed>
     *   }>,
     *   metadata?: array<string,mixed>
     * }
     */
    public function runHealthCheck(): array;
}
