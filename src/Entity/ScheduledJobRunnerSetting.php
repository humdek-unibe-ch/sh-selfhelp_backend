<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\ScheduledJobRunnerSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Operational settings for the Docker scheduled-job runner.
 *
 * A single row is maintained; the runner service creates the default row when
 * none exists. These are operational controls, intentionally kept out of CMS
 * preferences.
 */
#[ORM\Entity(repositoryClass: ScheduledJobRunnerSettingRepository::class)]
#[ORM\Table(name: 'scheduled_job_runner_settings')]
class ScheduledJobRunnerSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'enabled', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'interval_seconds', type: 'integer', options: ['default' => 60])]
    private int $intervalSeconds = 60;

    #[ORM\Column(name: 'max_jobs_per_run', type: 'integer', nullable: true, options: ['default' => 100])]
    private ?int $maxJobsPerRun = 100;

    #[ORM\Column(name: 'lock_ttl_seconds', type: 'integer', options: ['default' => 120])]
    private int $lockTtlSeconds = 120;

    #[ORM\Column(name: 'stale_running_after_seconds', type: 'integer', options: ['default' => 900])]
    private int $staleRunningAfterSeconds = 900;

    /**
     * How many of the most recent runner-run audit rows to keep. The runner
     * writes one {@see ScheduledJobRunnerRun} row per tick (~1/min by default),
     * so without a cap `scheduled_job_runner_runs` grows unbounded. After each
     * terminal run the rows beyond this many newest are pruned. NULL (or < 1)
     * disables pruning for operators who archive the history externally.
     */
    #[ORM\Column(name: 'retention_max_runs', type: 'integer', nullable: true, options: ['default' => 5000])]
    private ?int $retentionMaxRuns = 5000;

    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_updated_by_users', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function setIntervalSeconds(int $intervalSeconds): self
    {
        $this->intervalSeconds = $intervalSeconds;
        return $this;
    }

    public function getMaxJobsPerRun(): ?int
    {
        return $this->maxJobsPerRun;
    }

    public function setMaxJobsPerRun(?int $maxJobsPerRun): self
    {
        $this->maxJobsPerRun = $maxJobsPerRun;
        return $this;
    }

    public function getLockTtlSeconds(): int
    {
        return $this->lockTtlSeconds;
    }

    public function setLockTtlSeconds(int $lockTtlSeconds): self
    {
        $this->lockTtlSeconds = $lockTtlSeconds;
        return $this;
    }

    public function getStaleRunningAfterSeconds(): int
    {
        return $this->staleRunningAfterSeconds;
    }

    public function setStaleRunningAfterSeconds(int $staleRunningAfterSeconds): self
    {
        $this->staleRunningAfterSeconds = $staleRunningAfterSeconds;
        return $this;
    }

    public function getRetentionMaxRuns(): ?int
    {
        return $this->retentionMaxRuns;
    }

    public function setRetentionMaxRuns(?int $retentionMaxRuns): self
    {
        $this->retentionMaxRuns = $retentionMaxRuns;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}
