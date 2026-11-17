<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'data_rows')]
#[ORM\Index(name: 'idx_data_rows_id_data_tables', columns: ['id_data_tables'])]
// Ordered and owner-scoped pages and counts of the data query engine: the index order is
// `ORDER BY timestamp DESC, id DESC` and the deleted marker is in the index (see the migration).
#[ORM\Index(name: 'idx_data_rows_table_timestamp', columns: ['id_data_tables', 'timestamp', 'id', 'id_action_trigger_types'])]
#[ORM\Index(name: 'idx_data_rows_table_user_timestamp', columns: ['id_data_tables', 'id_users', 'timestamp', 'id', 'id_action_trigger_types'])]
class DataRow
{
    #[ORM\ManyToOne(targetEntity: DataTable::class, inversedBy: 'dataRows', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'id_data_tables', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?DataTable $dataTable = null;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, DataCell>
     */
    #[ORM\OneToMany(mappedBy: 'dataRow', targetEntity: DataCell::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $dataCells;

    public function __construct()
    {
        $this->dataCells = new \Doctrine\Common\Collections\ArrayCollection();
        $this->timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;


    #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(name: 'id_users', type: 'integer', nullable: true)]
    private ?int $idUsers = null;

    #[ORM\Column(name: 'id_action_trigger_types', type: 'integer', nullable: true)]
    private ?int $idActionTriggerTypes = null;

    /**
     * Version of the record: 1 when created, +1 for every persisted change. It is
     * advanced only by `DataRevisionService`, with one atomic statement inside the
     * write transaction, so two saves of the same version can never both succeed.
     */
    #[ORM\Column(name: 'version', type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataTable(): ?DataTable
    {
        return $this->dataTable;
    }
    public function setDataTable(?DataTable $dataTable): static
    {
        $this->dataTable = $dataTable;
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, DataCell>
     */
    public function getDataCells(): \Doctrine\Common\Collections\Collection
    {
        return $this->dataCells;
    }
    public function addDataCell(DataCell $dataCell): self
    {
        if (!$this->dataCells->contains($dataCell)) {
            $this->dataCells[] = $dataCell;
            $dataCell->setDataRow($this);
        }
        return $this;
    }
    public function removeDataCell(DataCell $dataCell): self
    {
        if ($this->dataCells->removeElement($dataCell)) {
            if ($dataCell->getDataRow() === $this) {
                $dataCell->setDataRow(null);
            }
        }
        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeInterface $timestamp): static
    {
        // Ensure UTC storage
        $this->timestamp = $timestamp instanceof \DateTimeImmutable
            ? ($timestamp->getTimezone()->getName() === 'UTC' ? $timestamp : $timestamp->setTimezone(new \DateTimeZone('UTC')))
            : \DateTimeImmutable::createFromMutable(
                $timestamp->getTimezone()->getName() === 'UTC'
                    ? $timestamp
                    : $timestamp->setTimezone(new \DateTimeZone('UTC'))
            );

        return $this;
    }

    public function getIdUsers(): ?int
    {
        return $this->idUsers;
    }

    public function setIdUsers(?int $idUsers): static
    {
        $this->idUsers = $idUsers;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Keep the in-memory copy in step after the database version was advanced
     * by `DataRevisionService` (the statement, not this setter, is what counts).
     */
    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getIdActionTriggerTypes(): ?int
    {
        return $this->idActionTriggerTypes;
    }

    public function setIdActionTriggerTypes(?int $idActionTriggerTypes): static
    {
        $this->idActionTriggerTypes = $idActionTriggerTypes;

        return $this;
    }
}
// ENTITY RULE
