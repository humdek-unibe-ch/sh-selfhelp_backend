<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use App\Repository\ScheduledJobRecipientRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One delivery target snapshot for a scheduled job.
 *
 * A scheduled email/notification job links to one or more recipient snapshots
 * so the executor can apply the recipient's communication preferences at
 * delivery time (issue #29) while still supporting external/shared mailboxes
 * that do not map to a SelfHelp user. The base `scheduled_jobs.id_users`
 * column stays the compatibility pointer for single-user jobs; this table is
 * the authoritative recipient record the executor prefers when present.
 */
#[ORM\Entity(repositoryClass: ScheduledJobRecipientRepository::class)]
#[ORM\Table(name: 'scheduled_job_recipients')]
#[ORM\Index(name: 'idx_scheduled_job_recipients_id_scheduled_jobs', columns: ['id_scheduled_jobs'])]
#[ORM\Index(name: 'idx_scheduled_job_recipients_id_users', columns: ['id_users'])]
class ScheduledJobRecipient
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_NOTIFICATION = 'notification';

    public const RECIPIENT_TYPE_TO = 'to';
    public const RECIPIENT_TYPE_CC = 'cc';
    public const RECIPIENT_TYPE_BCC = 'bcc';

    public const RESOLVED_FROM_USER = 'user';
    public const RESOLVED_FROM_EXTERNAL_EMAIL = 'external_email';
    public const RESOLVED_FROM_ACTION_CONFIG = 'action_config';
    public const RESOLVED_FROM_ADMIN_INPUT = 'admin_input';
    public const RESOLVED_FROM_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /**
     * The scheduled job this recipient belongs to.
     */
    #[ORM\ManyToOne(targetEntity: ScheduledJob::class, inversedBy: 'recipients')]
    #[ORM\JoinColumn(name: 'id_scheduled_jobs', nullable: false, onDelete: 'CASCADE')]
    private ScheduledJob $scheduledJob;

    /**
     * The user this recipient maps to, when one exists. NULL for
     * external/shared mailboxes that have no stored SelfHelp preference.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'channel', type: 'string', length: 32, options: ['default' => self::CHANNEL_EMAIL])]
    private string $channel = self::CHANNEL_EMAIL;

    #[ORM\Column(name: 'recipient_type', type: 'string', length: 16, options: ['default' => self::RECIPIENT_TYPE_TO])]
    private string $recipientType = self::RECIPIENT_TYPE_TO;

    #[ORM\Column(name: 'recipient_email', type: 'string', length: 255, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(name: 'delivery_policy', type: 'string', length: 64, options: ['default' => 'respect_user_preferences'])]
    private string $deliveryPolicy = 'respect_user_preferences';

    #[ORM\Column(name: 'resolved_from', type: 'string', length: 32, nullable: true)]
    private ?string $resolvedFrom = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScheduledJob(): ScheduledJob
    {
        return $this->scheduledJob;
    }

    public function setScheduledJob(ScheduledJob $scheduledJob): self
    {
        $this->scheduledJob = $scheduledJob;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    public function getRecipientType(): string
    {
        return $this->recipientType;
    }

    public function setRecipientType(string $recipientType): self
    {
        $this->recipientType = $recipientType;
        return $this;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): self
    {
        $this->recipientEmail = $recipientEmail;
        return $this;
    }

    public function getDeliveryPolicy(): string
    {
        return $this->deliveryPolicy;
    }

    public function setDeliveryPolicy(string $deliveryPolicy): self
    {
        $this->deliveryPolicy = $deliveryPolicy;
        return $this;
    }

    public function getResolvedFrom(): ?string
    {
        return $this->resolvedFrom;
    }

    public function setResolvedFrom(?string $resolvedFrom): self
    {
        $this->resolvedFrom = $resolvedFrom;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
// ENTITY RULE
