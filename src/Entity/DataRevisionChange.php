<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one revision did to one field of one language, or (for a checkpoint)
 * what that field held afterwards.
 *
 * A change names the field by its stable key and keeps the label it had then,
 * so a value can still be understood after the field is renamed or removed
 * (`id_data_cols` is only a convenience link and becomes NULL when the column
 * is gone). `before`/`after` point at immutable values. An empty text, a removed
 * field and a field that never existed are three different things: an empty text
 * is a value with no content, a removal has no `after`, and a field that never
 * existed has no row at all.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_revision_changes')]
#[ORM\Index(name: 'idx_data_revision_changes_revision', columns: ['id_data_revisions'])]
class DataRevisionChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DataRevision::class)]
    #[ORM\JoinColumn(name: 'id_data_revisions', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?DataRevision $revision = null;

    #[ORM\ManyToOne(targetEntity: DataCol::class)]
    #[ORM\JoinColumn(name: 'id_data_cols', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DataCol $dataCol = null;

    /** Stable key of the field (`section_<input id>` for a core form). */
    #[ORM\Column(name: 'field_key', type: Types::STRING, length: 255)]
    private string $fieldKey = '';

    /** The human label of the field when the change was made. */
    #[ORM\Column(name: 'field_label', type: Types::STRING, length: 255, nullable: true)]
    private ?string $fieldLabel = null;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(name: 'id_languages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Language $language = null;

    /** True for a checkpoint entry: the state after the revision, not a change. */
    #[ORM\Column(name: 'is_snapshot', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSnapshot = false;

    #[ORM\ManyToOne(targetEntity: DataRevisionValue::class)]
    #[ORM\JoinColumn(name: 'id_before_values', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?DataRevisionValue $beforeValue = null;

    /** NULL means the field is absent after the revision (removed). */
    #[ORM\ManyToOne(targetEntity: DataRevisionValue::class)]
    #[ORM\JoinColumn(name: 'id_after_values', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?DataRevisionValue $afterValue = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRevision(): ?DataRevision
    {
        return $this->revision;
    }

    public function setRevision(?DataRevision $revision): static
    {
        $this->revision = $revision;

        return $this;
    }

    public function getDataCol(): ?DataCol
    {
        return $this->dataCol;
    }

    public function setDataCol(?DataCol $dataCol): static
    {
        $this->dataCol = $dataCol;

        return $this;
    }

    public function getFieldKey(): string
    {
        return $this->fieldKey;
    }

    public function setFieldKey(string $fieldKey): static
    {
        $this->fieldKey = $fieldKey;

        return $this;
    }

    public function getFieldLabel(): ?string
    {
        return $this->fieldLabel;
    }

    public function setFieldLabel(?string $fieldLabel): static
    {
        $this->fieldLabel = $fieldLabel;

        return $this;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function isSnapshot(): bool
    {
        return $this->isSnapshot;
    }

    public function setIsSnapshot(bool $isSnapshot): static
    {
        $this->isSnapshot = $isSnapshot;

        return $this;
    }

    public function getBeforeValue(): ?DataRevisionValue
    {
        return $this->beforeValue;
    }

    public function setBeforeValue(?DataRevisionValue $beforeValue): static
    {
        $this->beforeValue = $beforeValue;

        return $this;
    }

    public function getAfterValue(): ?DataRevisionValue
    {
        return $this->afterValue;
    }

    public function setAfterValue(?DataRevisionValue $afterValue): static
    {
        $this->afterValue = $afterValue;

        return $this;
    }
}
