<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'data_cols')]
#[ORM\Index(name: 'idx_data_cols_id_data_tables', columns: ['id_data_tables'])]
#[ORM\UniqueConstraint(name: 'uq_data_cols_id_data_tables_field_key', columns: ['id_data_tables', 'field_key'])]
class DataCol
{
    /**
     * Auto-curated label provenance: the label tracks the incoming form/SurveyJS
     * label (only overwritten while it stays `auto`).
     */
    public const DISPLAY_NAME_SOURCE_AUTO = 'auto';

    /**
     * Admin-curated label provenance: a human edited the label, so auto label
     * pushes from submissions must never overwrite it.
     */
    public const DISPLAY_NAME_SOURCE_MANUAL = 'manual';

    #[ORM\ManyToOne(targetEntity: DataTable::class, inversedBy: 'dataCols', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'id_data_tables', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?DataTable $dataTable = null;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, DataCell>
     */
    #[ORM\OneToMany(mappedBy: 'dataCol', targetEntity: DataCell::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $dataCells;

    public function __construct()
    {
        $this->dataCells = new \Doctrine\Common\Collections\ArrayCollection();
    }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    /**
     * Immutable storage/submission key, treated as an opaque literal everywhere
     * (dotted SurveyJS keys are NOT object paths). Each data source defines how
     * the key is derived so it stays stable across label/name edits:
     *   - core CMS forms : `section_<input section id>` e.g. `section_42`
     *                      (renaming the input only changes display_name, never
     *                      the key — the section id is immutable);
     *   - SurveyJS       : `question.name`;
     *   - future sources : their own documented stable identifier.
     * No special collation: the key is an ASCII identifier, so the table's
     * default collation is sufficient and keeps Doctrine's schema in sync.
     */
    #[ORM\Column(name: 'field_key', type: 'string', length: 255, nullable: true)]
    private ?string $fieldKey = null;

    /**
     * Mutable, human-facing label. When null the reader falls back to field_key.
     */
    #[ORM\Column(name: 'display_name', type: 'string', length: 255, nullable: true)]
    private ?string $displayName = null;

    /**
     * Label provenance, modelled as a `lookups` row (type
     * `dataColDisplayNameSource`, codes `auto` | `manual`). A NULL FK means the
     * default `auto`: auto label pushes from submissions only update display_name
     * while the source is `auto`; once an admin curates the label the FK points at
     * the `manual` lookup and auto pushes never overwrite it again.
     */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_display_name_source', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Lookup $displayNameSource = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFieldKey(): ?string
    {
        return $this->fieldKey;
    }

    public function setFieldKey(?string $fieldKey): static
    {
        $this->fieldKey = $fieldKey;

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

    public function getDisplayNameSource(): ?Lookup
    {
        return $this->displayNameSource;
    }

    public function setDisplayNameSource(?Lookup $displayNameSource): static
    {
        $this->displayNameSource = $displayNameSource;

        return $this;
    }

    /**
     * Provenance code, resolved from the lookup FK. A NULL FK is the default
     * `auto`, so callers get a stable code without joining/null-checking.
     */
    public function getDisplayNameSourceCode(): string
    {
        return $this->displayNameSource?->getLookupCode() ?? self::DISPLAY_NAME_SOURCE_AUTO;
    }

    /**
     * True only when an admin has manually curated display_name (so auto label
     * pushes from new submissions must never overwrite it).
     */
    public function isDisplayNameManual(): bool
    {
        return $this->getDisplayNameSourceCode() === self::DISPLAY_NAME_SOURCE_MANUAL;
    }

    /**
     * Convenience: the human-facing label, falling back to the immutable key.
     */
    public function getLabel(): ?string
    {
        return $this->displayName ?? $this->fieldKey;
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
            $dataCell->setDataCol($this);
        }
        return $this;
    }

    public function removeDataCell(DataCell $dataCell): self
    {
        if ($this->dataCells->removeElement($dataCell)) {
            if ($dataCell->getDataCol() === $this) {
                $dataCell->setDataCol(null);
            }
        }
        return $this;
    }
}
// ENTITY RULE
