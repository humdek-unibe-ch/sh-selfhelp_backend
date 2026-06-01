<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'data_tables')]
#[ORM\Index(name: 'idx_data_tables_id_plugins', columns: ['id_plugins'])]
class DataTable
{
    /**
     * @var \Doctrine\Common\Collections\Collection<int, DataRow>
     */
    #[ORM\OneToMany(mappedBy: 'dataTable', targetEntity: DataRow::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $dataRows;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, DataCol>
     */
    #[ORM\OneToMany(mappedBy: 'dataTable', targetEntity: DataCol::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $dataCols;

    public function __construct()
    {
        $this->dataRows = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataCols = new \Doctrine\Common\Collections\ArrayCollection();
        $this->timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(name: 'timestamp', type: 'datetime_immutable')]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(name: 'display_name', type: 'string', length: 1000, nullable: true)]
    private ?string $displayName = null;

    /**
     * Plugin that owns this data_tables row. NULL = core-owned.
     * Plugin-owned data tables also use the `ownedDataTablePrefix`
     * naming convention so the purger can find their dataRows/Cells
     * tables even when the data_tables row itself has been deleted.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, DataRow>
     */
    public function getDataRows(): \Doctrine\Common\Collections\Collection
    {
        return $this->dataRows;
    }
    public function addDataRow(DataRow $dataRow): self
    {
        if (!$this->dataRows->contains($dataRow)) {
            $this->dataRows[] = $dataRow;
            $dataRow->setDataTable($this);
        }
        return $this;
    }
    public function removeDataRow(DataRow $dataRow): self
    {
        if ($this->dataRows->removeElement($dataRow)) {
            if ($dataRow->getDataTable() === $this) {
                $dataRow->setDataTable(null);
            }
        }
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, DataCol>
     */
    public function getDataCols(): \Doctrine\Common\Collections\Collection
    {
        return $this->dataCols;
    }
    public function addDataCol(DataCol $dataCol): self
    {
        if (!$this->dataCols->contains($dataCol)) {
            $this->dataCols[] = $dataCol;
            $dataCol->setDataTable($this);
        }
        return $this;
    }
    public function removeDataCol(DataCol $dataCol): self
    {
        if ($this->dataCols->removeElement($dataCol)) {
            if ($dataCol->getDataTable() === $this) {
                $dataCol->setDataTable(null);
            }
        }
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getPlugin(): ?\App\Entity\Plugin\Plugin
    {
        return $this->plugin;
    }

    public function setPlugin(?\App\Entity\Plugin\Plugin $plugin): static
    {
        $this->plugin = $plugin;

        return $this;
    }
}
// ENTITY RULE
