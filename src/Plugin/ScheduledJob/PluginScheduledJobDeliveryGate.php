<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Entity\ScheduledJob;
use App\Entity\User;

/**
 * Decides whether a plugin scheduled-job's user-facing delivery must be skipped
 * because the recipient disabled that communication channel (issue #36).
 *
 * Pure policy with no side effects: {@see \App\Service\Core\JobSchedulerService}
 * consults it before invoking a plugin handler and turns a blocked channel into
 * the same audited `skipped_*` outcome a core email/notification job produces.
 */
final class PluginScheduledJobDeliveryGate
{
    /**
     * Return the blocked channel
     * ({@see PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL} or
     * {@see PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION}) when
     * the handler declares a user-facing delivery the recipient disabled, or
     * `null` when delivery may proceed.
     *
     * Delivery proceeds (returns `null`) when the handler does not declare its
     * delivery, when it is flagged required-system, when there is no
     * preference-bearing recipient, or when the recipient still accepts the
     * declared channel.
     */
    public function blockedChannel(PluginScheduledJobHandlerInterface $handler, ScheduledJob $job): ?string
    {
        if (!$handler instanceof PluginScheduledJobDeliveryAwareInterface) {
            return null;
        }

        if ($handler->isRequiredSystemDelivery($job)) {
            return null;
        }

        $recipient = $handler->getDeliveryRecipient($job);
        if (!$recipient instanceof User) {
            return null;
        }

        return match ($handler->getDeliveryChannel($job)) {
            PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL =>
                $recipient->receivesEmails() ? null : PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL,
            PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION =>
                $recipient->receivesNotifications() ? null : PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION,
            default => null,
        };
    }
}
