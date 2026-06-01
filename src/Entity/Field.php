<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fields')]
#[ORM\UniqueConstraint(name: 'uq_fields_name', columns: ['name'])]
#[ORM\Index(name: 'idx_fields_id_field_types', columns: ['id_field_types'])]
#[ORM\Index(name: 'idx_fields_id_plugins', columns: ['id_plugins'])]
class Field
{
    public function __construct()
    {
        $this->stylesFields = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /** @var \Doctrine\Common\Collections\Collection<int, StylesField> */
    #[ORM\OneToMany(mappedBy: 'field', targetEntity: StylesField::class)]
    private \Doctrine\Common\Collections\Collection $stylesFields;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: FieldType::class, inversedBy: 'fields', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'id_field_types', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?FieldType $type = null;

    #[ORM\Column(name: 'display', type: 'boolean')]
    private bool $display = true;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'config', type: 'json', nullable: true)]
    private ?array $config = null;

    /**
     * Plugin that owns this field row. NULL = core-owned.
     * `ON DELETE SET NULL` so dropping a plugin row never silently
     * deletes CMS field definitions.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): ?FieldType
    {
        return $this->type;
    }

    public function setType(?FieldType $type): static
    {
        $this->type = $type;

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

    /** @return \Doctrine\Common\Collections\Collection<int, StylesField>|null */
    public function getStylesFields(): ?\Doctrine\Common\Collections\Collection
    {
        return $this->stylesFields;
    }

    public function addStylesField(StylesField $stylesField): static
    {
        if (!$this->stylesFields->contains($stylesField)) {
            $this->stylesFields[] = $stylesField;
            $stylesField->setField($this);
        }
        return $this;
    }

    public function removeStylesField(StylesField $stylesField): static
    {
        if ($this->stylesFields->contains($stylesField)) {
            $this->stylesFields->removeElement($stylesField);
            if ($stylesField->getField() === $this) {
                $stylesField->setField(null);
            }
        }
        return $this;
    }

    public function isDisplay(): ?bool
    {
        return $this->display;
    }

    public function setDisplay(bool $display): static
    {
        $this->display = $display;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /** @param array<string, mixed>|null $config */
    public function setConfig(?array $config): static
    {
        $this->config = $config;
        return $this;
    }
}
// ENTITY RULE
