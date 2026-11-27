<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\StyleGroupRepository")]
#[ORM\Table(name: 'style_groups')]
#[ORM\Index(name: 'idx_style_groups_id_plugins', columns: ['id_plugins'])]
#[ORM\UniqueConstraint(name: 'uq_style_groups_name', columns: ['name'])]
class StyleGroup
{
    public function __construct()
    {
        $this->styles = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /** @var \Doctrine\Common\Collections\Collection<int, Style> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: Style::class)]
    private \Doctrine\Common\Collections\Collection $styles;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100, unique: true)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'position', type: 'integer', nullable: true)]
    private ?int $position = null;

    /**
     * Plugin that declared this row in its manifest. NULL = core-owned.
     * Written by the plugin installer ({@see \App\Plugin\Lifecycle\PluginCatalogueSynchronizer});
     * `ON DELETE SET NULL` like styles and fields, purge deletes the rows.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;

    public function getPlugin(): ?\App\Entity\Plugin\Plugin
    {
        return $this->plugin;
    }

    public function setPlugin(?\App\Entity\Plugin\Plugin $plugin): static
    {
        $this->plugin = $plugin;

        return $this;
    }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return \Doctrine\Common\Collections\Collection<int, Style>|null */
    public function getStyles(): ?\Doctrine\Common\Collections\Collection
    {
        return $this->styles;
    }

    public function addStyle(Style $style): static
    {
        if (!$this->styles->contains($style)) {
            $this->styles[] = $style;
            $style->setGroup($this);
        }
        return $this;
    }

    public function removeStyle(Style $style): static
    {
        if ($this->styles->contains($style)) {
            $this->styles->removeElement($style);
            if ($style->getGroup() === $this) {
                $style->setGroup(null);
            }
        }
        return $this;
    }
}
// ENTITY RULE
