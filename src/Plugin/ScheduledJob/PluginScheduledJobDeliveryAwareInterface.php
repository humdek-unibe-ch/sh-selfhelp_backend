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
 * Opt-in contract extension that lets the host enforce a user's communication
 * preferences on plugin-contributed scheduled jobs (issue #36).
 *
 * Core `email` / `notification` jobs already honour
 * {@see User::receivesEmails()} / {@see User::receivesNotifications()} inside
 * {@see \App\Service\Core\JobSchedulerService}. Plugin job types are dispatched
 * BEFORE the core executors, so a plugin that delivers user-facing email or push
 * would otherwise bypass that gate silently.
 *
 * Any plugin scheduled-job handler ({@see PluginScheduledJobHandlerInterface})
 * that sends user-facing email or notifications MUST implement this interface so
 * the host can hold it to the same preference contract. When a handler declares
 * a recipient + channel, the host checks the recipient's preference and skips
 * the job (terminal `skipped_*` status, the handler is NOT invoked) when the
 * channel is disabled — exactly like a core job. Handlers that deliver nothing
 * to a single preference-bearing user (broadcasts, data cleanup, admin mail)
 * simply do not implement this interface.
 *
 * Handlers that genuinely send account/security mail that must ignore the
 * preference (mirroring the core `required_system` delivery policy) return
 * `true` from {@see isRequiredSystemDelivery()}.
 */
interface PluginScheduledJobDeliveryAwareInterface
{
    /** Email channel — gated by {@see User::receivesEmails()}. */
    public const CHANNEL_EMAIL = 'email';

    /** Push-notification channel — gated by {@see User::receivesNotifications()}. */
    public const CHANNEL_NOTIFICATION = 'notification';

    /**
     * The user-facing delivery channel this job uses for the given job, or
     * `null` when this particular job delivers nothing that a preference gates.
     * Must return {@see CHANNEL_EMAIL} or {@see CHANNEL_NOTIFICATION}.
     */
    public function getDeliveryChannel(ScheduledJob $job): ?string;

    /**
     * The user whose communication preference gates delivery, or `null` when
     * there is no single preference-bearing SelfHelp recipient (e.g. an
     * external address or a broadcast).
     */
    public function getDeliveryRecipient(ScheduledJob $job): ?User;

    /**
     * Whether this job is required system delivery (account/security) that must
     * bypass the user's preference, mirroring the core `required_system` email
     * delivery policy. Most handlers return `false`.
     */
    public function isRequiredSystemDelivery(ScheduledJob $job): bool;
}
