<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Core scheduled-job entity used for emails, notifications, tasks, and reminders.
 *
 * Reminder-specific lineage and validity metadata lives in the associated
 * `ScheduledJobReminder` entity so the base job table stays focused on common fields.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scheduled_jobs')]
#[ORM\Index(name: 'idx_scheduled_jobs_id_users', columns: ['id_users'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_actions', columns: ['id_actions'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_data_tables', columns: ['id_data_tables'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_data_rows', columns: ['id_data_rows'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_job_status', columns: ['id_job_status'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_job_types', columns: ['id_job_types'])]
#[ORM\Index(name: 'idx_scheduled_jobs_date_to_be_executed', columns: ['date_to_be_executed'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_users_date_to_be_executed', columns: ['id_users', 'date_to_be_executed'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_job_types_id_job_status', columns: ['id_job_types', 'id_job_status'])]
#[ORM\Index(name: 'idx_scheduled_jobs_id_data_tables_id_users', columns: ['id_data_tables', 'id_users'])]
class ScheduledJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /**
     * The user who receives or owns the scheduled job.
     *
     * Nullable for system-scoped jobs that are not tied to one user.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * The source action that generated the scheduled job, when applicable.
     */
    #[ORM\ManyToOne(targetEntity: Action::class)]
    #[ORM\JoinColumn(name: 'id_actions', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    /**
     * The source data table associated with the job trigger.
     */
    #[ORM\ManyToOne(targetEntity: DataTable::class)]
    #[ORM\JoinColumn(name: 'id_data_tables', nullable: true, onDelete: 'CASCADE')]
    private ?DataTable $dataTable = null;

    /**
     * The source data row associated with the job trigger.
     */
    #[ORM\ManyToOne(targetEntity: DataRow::class)]
    #[ORM\JoinColumn(name: 'id_data_rows', nullable: true, onDelete: 'CASCADE')]
    private ?DataRow $dataRow = null;

    /**
     * Reminder-specific metadata stored separately from the base scheduled-job table.
     */
    #[ORM\OneToOne(mappedBy: 'scheduledJob', targetEntity: ScheduledJobReminder::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?ScheduledJobReminder $reminderMetadata = null;

    /**
     * Lookup describing the persisted job type.
     */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_job_types', nullable: false, onDelete: 'CASCADE')]
    private Lookup $jobType;

    /**
     * Lookup describing the current execution status.
     */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_job_status', nullable: false, onDelete: 'CASCADE')]
    private Lookup $status;


    #[ORM\Column(name: 'date_create', type: 'datetime')]
    private \DateTimeInterface $dateCreate;

    #[ORM\Column(name: 'date_to_be_executed', type: 'datetime')]
    private \DateTimeInterface $dateToBeExecuted;

    #[ORM\Column(name: 'date_executed', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateExecuted = null;


    /**
     * Human-readable description shown in admin tooling and transactions.
     */
    #[ORM\Column(name: 'description', type: 'string', length: 1000, nullable: true)]
    private ?string $description = null;

    /**
     * Structured job payload used during execution and admin display.
     */
    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'config', type: 'json', nullable: true)]
    private ?array $config = null;

    public function __construct()
    {
        $this->dateCreate = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // Core relationship getters/setters
    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getAction(): ?Action
    {
        return $this->action;
    }

    public function setAction(?Action $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getDataTable(): ?DataTable
    {
        return $this->dataTable;
    }

    public function setDataTable(?DataTable $dataTable): self
    {
        $this->dataTable = $dataTable;
        return $this;
    }

    public function getDataRow(): ?DataRow
    {
        return $this->dataRow;
    }

    public function setDataRow(?DataRow $dataRow): self
    {
        $this->dataRow = $dataRow;
        return $this;
    }

    public function getReminderMetadata(): ?ScheduledJobReminder
    {
        return $this->reminderMetadata;
    }

    public function setReminderMetadata(?ScheduledJobReminder $reminderMetadata): self
    {
        $this->reminderMetadata = $reminderMetadata;
        if ($reminderMetadata && $reminderMetadata->getScheduledJob() !== $this) {
            $reminderMetadata->setScheduledJob($this);
        }
        return $this;
    }

    // Job classification getters/setters
    public function getJobType(): Lookup
    {
        return $this->jobType;
    }

    public function setJobType(Lookup $jobType): self
    {
        $this->jobType = $jobType;
        return $this;
    }

    public function getStatus(): Lookup
    {
        return $this->status;
    }

    public function setStatus(Lookup $status): self
    {
        $this->status = $status;
        return $this;
    }


    // Date getters/setters
    public function getDateCreate(): \DateTimeInterface
    {
        return $this->dateCreate;
    }

    public function setDateCreate(\DateTimeInterface $dateCreate): self
    {
        $this->dateCreate = self::toMutable($dateCreate);
        return $this;
    }

    public function getDateToBeExecuted(): \DateTimeInterface
    {
        return $this->dateToBeExecuted;
    }

    public function setDateToBeExecuted(\DateTimeInterface $dateToBeExecuted): self
    {
        $this->dateToBeExecuted = self::toMutable($dateToBeExecuted);
        return $this;
    }

    public function getDateExecuted(): ?\DateTimeInterface
    {
        return $this->dateExecuted;
    }

    public function setDateExecuted(?\DateTimeInterface $dateExecuted): self
    {
        $this->dateExecuted = $dateExecuted === null ? null : self::toMutable($dateExecuted);
        return $this;
    }

    /**
     * Normalize any datetime to a mutable \DateTime.
     *
     * The scheduled_jobs date columns are Doctrine `datetime` (mutable) type,
     * but callers such as the action schedule calculator legitimately produce
     * \DateTimeImmutable (per the UTC-immutable datetime convention). Without
     * this normalization, persisting an immutable value throws
     * "Could not convert PHP value of type DateTimeImmutable to type
     * Doctrine\DBAL\Types\DateTimeType", which silently aborts job scheduling.
     * The original timezone (UTC) is preserved.
     */
    private static function toMutable(\DateTimeInterface $value): \DateTime
    {
        return $value instanceof \DateTimeImmutable
            ? \DateTime::createFromImmutable($value)
            : $value;
    }

    // Job details getters/setters
    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): self
    {
        $this->config = $config;
        return $this;
    }

}
// ENTITY RULE
