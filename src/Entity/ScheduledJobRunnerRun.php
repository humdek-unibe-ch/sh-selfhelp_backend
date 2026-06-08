<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\ScheduledJobRunnerRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit/history record for one execution of the scheduled-job runner.
 */
#[ORM\Entity(repositoryClass: ScheduledJobRunnerRunRepository::class)]
#[ORM\Table(name: 'scheduled_job_runner_runs')]
#[ORM\Index(name: 'idx_scheduled_job_runner_runs_started_at', columns: ['started_at'])]
class ScheduledJobRunnerRun
{
    public const TRIGGER_SCHEDULER = 'scheduler';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SYSTEM = 'system';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_DISABLED = 'skipped_disabled';
    public const STATUS_SKIPPED_INTERVAL = 'skipped_interval';
    public const STATUS_SKIPPED_LOCKED = 'skipped_locked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'trigger_type', type: 'string', length: 32)]
    private string $triggerType = self::TRIGGER_SCHEDULER;

    #[ORM\Column(name: 'status', type: 'string', length: 32)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(name: 'started_at', type: 'datetime')]
    private \DateTimeInterface $startedAt;

    #[ORM\Column(name: 'finished_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $finishedAt = null;

    #[ORM\Column(name: 'duration_ms', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'due_count', type: 'integer', options: ['default' => 0])]
    private int $dueCount = 0;

    #[ORM\Column(name: 'attempted_count', type: 'integer', options: ['default' => 0])]
    private int $attemptedCount = 0;

    #[ORM\Column(name: 'done_count', type: 'integer', options: ['default' => 0])]
    private int $doneCount = 0;

    #[ORM\Column(name: 'failed_count', type: 'integer', options: ['default' => 0])]
    private int $failedCount = 0;

    #[ORM\Column(name: 'skipped_count', type: 'integer', options: ['default' => 0])]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'lock_acquired', type: 'boolean', options: ['default' => false])]
    private bool $lockAcquired = false;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'settings_snapshot', type: 'json', nullable: true)]
    private ?array $settingsSnapshot = null;

    public function __construct()
    {
        $this->startedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): self
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;
        return $this;
    }

    public function getDueCount(): int
    {
        return $this->dueCount;
    }

    public function setDueCount(int $dueCount): self
    {
        $this->dueCount = $dueCount;
        return $this;
    }

    public function getAttemptedCount(): int
    {
        return $this->attemptedCount;
    }

    public function setAttemptedCount(int $attemptedCount): self
    {
        $this->attemptedCount = $attemptedCount;
        return $this;
    }

    public function getDoneCount(): int
    {
        return $this->doneCount;
    }

    public function setDoneCount(int $doneCount): self
    {
        $this->doneCount = $doneCount;
        return $this;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function setFailedCount(int $failedCount): self
    {
        $this->failedCount = $failedCount;
        return $this;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function setSkippedCount(int $skippedCount): self
    {
        $this->skippedCount = $skippedCount;
        return $this;
    }

    public function isLockAcquired(): bool
    {
        return $this->lockAcquired;
    }

    public function setLockAcquired(bool $lockAcquired): self
    {
        $this->lockAcquired = $lockAcquired;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSettingsSnapshot(): ?array
    {
        return $this->settingsSnapshot;
    }

    /** @param array<string, mixed>|null $settingsSnapshot */
    public function setSettingsSnapshot(?array $settingsSnapshot): self
    {
        $this->settingsSnapshot = $settingsSnapshot;
        return $this;
    }
}
