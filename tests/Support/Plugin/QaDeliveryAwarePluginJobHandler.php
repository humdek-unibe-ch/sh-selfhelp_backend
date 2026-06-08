<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Plugin\ScheduledJob\PluginScheduledJobDeliveryAwareInterface;
use App\Plugin\ScheduledJob\PluginScheduledJobHandlerInterface;

/**
 * QA double for a plugin scheduled-job handler that delivers user-facing email
 * and declares it via {@see PluginScheduledJobDeliveryAwareInterface} (issue
 * #36). Registered in `config/services_test.yaml`, so it is collected into the
 * real {@see \App\Plugin\ScheduledJob\PluginScheduledJobRegistry} and lets
 * {@see \App\Tests\Integration\Service\Core\PluginScheduledJobPreferenceTest}
 * prove the host gate skips it (without invoking it) when the recipient
 * disabled emails. Claims a `qa_`-scoped job type so it never shadows a real
 * one.
 */
final class QaDeliveryAwarePluginJobHandler implements
    PluginScheduledJobHandlerInterface,
    PluginScheduledJobDeliveryAwareInterface
{
    public const JOB_TYPE = 'qa_plugin_delivery';

    /** How many times {@see execute()} actually ran (0 means the gate skipped it). */
    public int $executions = 0;

    public function getSupportedJobType(): string
    {
        return self::JOB_TYPE;
    }

    public function execute(ScheduledJob $job, string $transactionBy): bool
    {
        $this->executions++;

        return true;
    }

    public function getDeliveryChannel(ScheduledJob $job): string
    {
        return self::CHANNEL_EMAIL;
    }

    public function getDeliveryRecipient(ScheduledJob $job): ?User
    {
        return $job->getUser();
    }

    public function isRequiredSystemDelivery(ScheduledJob $job): bool
    {
        return false;
    }
}
