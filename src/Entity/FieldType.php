<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'field_types')]
#[ORM\Index(name: 'idx_field_types_id_plugins', columns: ['id_plugins'])]
#[ORM\UniqueConstraint(name: 'uq_field_types_name', columns: ['name'])]
class FieldType
{
    public function __construct()
    {
        $this->fields = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /** @var \Doctrine\Common\Collections\Collection<int, Field> */
    #[ORM\OneToMany(mappedBy: 'type', targetEntity: Field::class)]
    private \Doctrine\Common\Collections\Collection $fields;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(name: 'position', type: 'integer')]
    private int $position;

    /**
     * Plugin that declared this row in its manifest. NULL = core-owned.
     * Written by the plugin installer ({@see \App\Plugin\Lifecycle\PluginCatalogueSynchronizer});
     * `ON DELETE SET NULL` like styles and fields, purge deletes the rows.
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

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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

    /** @return \Doctrine\Common\Collections\Collection<int, Field>|null */
    public function getFields(): ?\Doctrine\Common\Collections\Collection
    {
        return $this->fields;
    }

    public function addField(Field $field): static
    {
        if (!$this->fields->contains($field)) {
            $this->fields[] = $field;
            $field->setType($this);
        }
        return $this;
    }

    public function removeField(Field $field): static
    {
        if ($this->fields->contains($field)) {
            $this->fields->removeElement($field);
            if ($field->getType() === $this) {
                $field->setType(null);
            }
        }
        return $this;
    }
}
// ENTITY RULE
