<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduledJobs',indexes: [
    new ORM\Index(name: 'IDX_3E186B37FA06E4D9', columns: ['id_users']),
    new ORM\Index(name: 'IDX_3E186B37DBD5589F', columns: ['id_actions']),
    new ORM\Index(name: 'IDX_3E186B37E2E6A7C3', columns: ['id_dataTables']),
    new ORM\Index(name: 'IDX_3E186B37F3854F45', columns: ['id_dataRows']),
    new ORM\Index(name: 'IDX_3E186B3777FD8DE1', columns: ['id_jobStatus']),
    new ORM\Index(name: 'IDX_3E186B3712C34CFB', columns: ['id_jobTypes']),
    new ORM\Index(name: 'IDX_3E186B37B1E3B97B', columns: ['date_to_be_executed']),
    new ORM\Index(name: 'index_id_users_date_to_be_executed', columns: ['id_users', 'date_to_be_executed']),
    new ORM\Index(name: 'IDX_3E186B3712C34CFB77FD8DE1', columns: ['id_jobTypes', 'id_jobStatus']),
    new ORM\Index(name: 'IDX_3E186B37E2E6A7C3A76ED395', columns: ['id_dataTables', 'id_users']),
])]
class ScheduledJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    // Core relationships (nullable for system jobs)
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Action::class)]
    #[ORM\JoinColumn(name: 'id_actions', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\ManyToOne(targetEntity: DataTable::class)]
    #[ORM\JoinColumn(name: 'id_dataTables', nullable: true, onDelete: 'CASCADE')]
    private ?DataTable $dataTable = null;

    #[ORM\ManyToOne(targetEntity: DataRow::class)]
    #[ORM\JoinColumn(name: 'id_dataRows', nullable: true, onDelete: 'CASCADE')]
    private ?DataRow $dataRow = null;

    // Job classification (lookup-based)
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_jobTypes', nullable: false, onDelete: 'CASCADE')]
    private Lookup $jobType;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_jobStatus', nullable: false, onDelete: 'CASCADE')]
    private Lookup $status;


    #[ORM\Column(name: 'date_create', type: 'datetime')]
    private \DateTimeInterface $dateCreate;

    #[ORM\Column(name: 'date_to_be_executed', type: 'datetime')]
    private \DateTimeInterface $dateToBeExecuted;

    #[ORM\Column(name: 'date_executed', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $dateExecuted = null;



    // Job details
    #[ORM\Column(name: 'description', type: 'string', length: 1000, nullable: true)]
    private ?string $description = null;

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
        $this->dateCreate = $dateCreate;
        return $this;
    }

    public function getDateToBeExecuted(): \DateTimeInterface
    {
        return $this->dateToBeExecuted;
    }

    public function setDateToBeExecuted(\DateTimeInterface $dateToBeExecuted): self
    {
        $this->dateToBeExecuted = $dateToBeExecuted;
        return $this;
    }

    public function getDateExecuted(): ?\DateTimeInterface
    {
        return $this->dateExecuted;
    }

    public function setDateExecuted(?\DateTimeInterface $dateExecuted): self
    {
        $this->dateExecuted = $dateExecuted;
        return $this;
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

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;
        return $this;
    }
}
// ENTITY RULE
