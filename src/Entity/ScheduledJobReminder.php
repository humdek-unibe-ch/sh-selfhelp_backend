<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Stores reminder-only metadata for scheduled jobs.
 *
 * This keeps reminder lineage and session-window data out of the base
 * `scheduledJobs` table while still allowing efficient cleanup queries.
 */
#[ORM\Entity]
#[ORM\Table(name: 'scheduledJobs_reminders', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'UNIQ_SJR_JOB', columns: ['id_scheduledJobs']),
], indexes: [
    new ORM\Index(name: 'IDX_SJR_PARENT', columns: ['id_parentScheduledJobs']),
    new ORM\Index(name: 'IDX_SJR_TABLE', columns: ['id_dataTables']),
])]
class ScheduledJobReminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /**
     * The reminder job that owns this metadata row.
     */
    #[ORM\OneToOne(inversedBy: 'reminderMetadata', targetEntity: ScheduledJob::class)]
    #[ORM\JoinColumn(name: 'id_scheduledJobs', nullable: false, onDelete: 'CASCADE')]
    private ?ScheduledJob $scheduledJob = null;

    /**
     * The parent scheduled job that spawned this reminder.
     */
    #[ORM\ManyToOne(targetEntity: ScheduledJob::class)]
    #[ORM\JoinColumn(name: 'id_parentScheduledJobs', nullable: true, onDelete: 'SET NULL')]
    private ?ScheduledJob $parentJob = null;

    /**
     * The target data table whose completion invalidates the reminder.
     */
    #[ORM\ManyToOne(targetEntity: DataTable::class)]
    #[ORM\JoinColumn(name: 'id_dataTables', nullable: true, onDelete: 'SET NULL')]
    private ?DataTable $reminderDataTable = null;

    /**
     * Start of the reminder validity window used for cleanup.
     */
    #[ORM\Column(name: 'session_start_date', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sessionStartDate = null;

    /**
     * End of the reminder validity window used for cleanup.
     */
    #[ORM\Column(name: 'session_end_date', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sessionEndDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScheduledJob(): ?ScheduledJob
    {
        return $this->scheduledJob;
    }

    public function setScheduledJob(?ScheduledJob $scheduledJob): self
    {
        $this->scheduledJob = $scheduledJob;
        if ($scheduledJob && $scheduledJob->getReminderMetadata() !== $this) {
            $scheduledJob->setReminderMetadata($this);
        }
        return $this;
    }

    public function getParentJob(): ?ScheduledJob
    {
        return $this->parentJob;
    }

    public function setParentJob(?ScheduledJob $parentJob): self
    {
        $this->parentJob = $parentJob;
        return $this;
    }

    public function getReminderDataTable(): ?DataTable
    {
        return $this->reminderDataTable;
    }

    public function setReminderDataTable(?DataTable $reminderDataTable): self
    {
        $this->reminderDataTable = $reminderDataTable;
        return $this;
    }

    public function getSessionStartDate(): ?\DateTimeInterface
    {
        return $this->sessionStartDate;
    }

    public function setSessionStartDate(?\DateTimeInterface $sessionStartDate): self
    {
        $this->sessionStartDate = $sessionStartDate;
        return $this;
    }

    public function getSessionEndDate(): ?\DateTimeInterface
    {
        return $this->sessionEndDate;
    }

    public function setSessionEndDate(?\DateTimeInterface $sessionEndDate): self
    {
        $this->sessionEndDate = $sessionEndDate;
        return $this;
    }
}
