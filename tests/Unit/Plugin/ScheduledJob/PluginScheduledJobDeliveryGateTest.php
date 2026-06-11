<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Plugin\ScheduledJob;

use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Plugin\ScheduledJob\PluginScheduledJobDeliveryAwareInterface;
use App\Plugin\ScheduledJob\PluginScheduledJobDeliveryGate;
use App\Plugin\ScheduledJob\PluginScheduledJobHandlerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Issue #36: the pure policy that lets the host hold a plugin scheduled-job to
 * the same communication-preference contract as core email/notification jobs.
 *
 * Delivery is blocked only when a handler explicitly declares a user-facing
 * channel + a preference-bearing recipient who disabled that channel; everything
 * else (no declaration, required-system, no recipient, channel still accepted)
 * lets delivery proceed.
 */
final class PluginScheduledJobDeliveryGateTest extends TestCase
{
    private PluginScheduledJobDeliveryGate $gate;

    protected function setUp(): void
    {
        $this->gate = new PluginScheduledJobDeliveryGate();
    }

    public function testPlainHandlerWithoutDeliveryDeclarationIsNeverGated(): void
    {
        $handler = new class implements PluginScheduledJobHandlerInterface {
            public function getSupportedJobType(): string
            {
                return 'qa_plain';
            }

            public function execute(ScheduledJob $job, string $transactionBy): bool
            {
                return true;
            }
        };

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    public function testEmailDeliveryIsBlockedWhenRecipientDisabledEmails(): void
    {
        $user = (new User())->setReceivesEmails(false);
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL, $user, false);

        self::assertSame(
            PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL,
            $this->gate->blockedChannel($handler, new ScheduledJob())
        );
    }

    public function testEmailDeliveryProceedsWhenRecipientAllowsEmails(): void
    {
        $user = (new User())->setReceivesEmails(true);
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL, $user, false);

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    public function testNotificationDeliveryIsBlockedWhenRecipientDisabledNotifications(): void
    {
        $user = (new User())->setReceivesNotifications(false);
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION, $user, false);

        self::assertSame(
            PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION,
            $this->gate->blockedChannel($handler, new ScheduledJob())
        );
    }

    public function testNotificationDeliveryProceedsWhenRecipientAllowsNotifications(): void
    {
        $user = (new User())->setReceivesNotifications(true);
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION, $user, false);

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    public function testRequiredSystemDeliveryBypassesPreference(): void
    {
        $user = (new User())->setReceivesEmails(false);
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL, $user, true);

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    public function testNoPreferenceBearingRecipientIsNeverGated(): void
    {
        $handler = $this->deliveryAware(PluginScheduledJobDeliveryAwareInterface::CHANNEL_EMAIL, null, false);

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    public function testUndeclaredChannelIsNeverGated(): void
    {
        $user = (new User())->setReceivesEmails(false)->setReceivesNotifications(false);
        $handler = $this->deliveryAware(null, $user, false);

        self::assertNull($this->gate->blockedChannel($handler, new ScheduledJob()));
    }

    /**
     * Build a delivery-aware plugin handler double with a fixed declared channel,
     * recipient and required-system flag.
     */
    private function deliveryAware(
        ?string $channel,
        ?User $recipient,
        bool $requiredSystem,
    ): PluginScheduledJobHandlerInterface {
        return new class($channel, $recipient, $requiredSystem) implements
            PluginScheduledJobHandlerInterface,
            PluginScheduledJobDeliveryAwareInterface {
            public function __construct(
                private readonly ?string $channel,
                private readonly ?User $recipient,
                private readonly bool $requiredSystem,
            ) {
            }

            public function getSupportedJobType(): string
            {
                return 'qa_delivery_aware';
            }

            public function execute(ScheduledJob $job, string $transactionBy): bool
            {
                return true;
            }

            public function getDeliveryChannel(ScheduledJob $job): ?string
            {
                return $this->channel;
            }

            public function getDeliveryRecipient(ScheduledJob $job): ?User
            {
                return $this->recipient;
            }

            public function isRequiredSystemDelivery(ScheduledJob $job): bool
            {
                return $this->requiredSystem;
            }
        };
    }
}
