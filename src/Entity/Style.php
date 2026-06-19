<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\StyleRepository")]
#[ORM\Table(name: 'styles')]
#[ORM\UniqueConstraint(name: 'uq_styles_name', columns: ['name'])]
#[ORM\Index(name: 'idx_styles_id_style_groups', columns: ['id_style_groups'])]
#[ORM\Index(name: 'idx_styles_id_plugins', columns: ['id_plugins'])]
#[ORM\Index(name: 'idx_styles_id_render_target', columns: ['id_render_target'])]
class Style
{
    public function __construct()
    {
        $this->stylesFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->allowedChildrenRelationships = new \Doctrine\Common\Collections\ArrayCollection();
        $this->allowedParentsRelationships = new \Doctrine\Common\Collections\ArrayCollection();
    }
    /** @var \Doctrine\Common\Collections\Collection<int, StylesField> */
    #[ORM\OneToMany(mappedBy: 'style', targetEntity: StylesField::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $stylesFields;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(name: 'can_have_children', type: 'boolean', options: ['default' => 0])]
    private bool $canHaveChildren = false;

    // id_style_groups is mapped through the $group association below.
    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: StyleGroup::class, inversedBy: 'styles')]
    #[ORM\JoinColumn(name: 'id_style_groups', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?StyleGroup $group = null;

    /** @var \Doctrine\Common\Collections\Collection<int, StylesAllowedRelationship> */
    #[ORM\OneToMany(mappedBy: 'parentStyle', targetEntity: StylesAllowedRelationship::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $allowedChildrenRelationships;

    /** @var \Doctrine\Common\Collections\Collection<int, StylesAllowedRelationship> */
    #[ORM\OneToMany(mappedBy: 'childStyle', targetEntity: StylesAllowedRelationship::class, cascade: ['persist', 'remove'])]
    private \Doctrine\Common\Collections\Collection $allowedParentsRelationships;

    /**
     * Plugin that owns this style row. NULL = core-owned (the default).
     * `ON DELETE SET NULL` is intentional - dropping a plugin record
     * must not silently delete CMS content. The PluginPurger is the
     * only code path that actually deletes plugin-owned rows.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;

    /**
     * Render target for this style, lookup-backed by `styleRenderTargets`
     * (`web` | `mobile` | `both`). NULL is treated as `both` by the catalog
     * serializer, so legacy rows keep rendering on every platform.
     * `ON DELETE SET NULL` matches the other lookup FKs.
     *
     * This is distinct from the request *client* platform (resolved by
     * {@see \App\Service\Core\VariableResolverService::getPlatform()}) and from
     * the *page access* target (`pages.id_page_access_types`). It declares only
     * where a style is intentionally renderable.
     */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_render_target', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Lookup $renderTarget = null;

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

    public function getCanHaveChildren(): ?bool
    {
        return $this->canHaveChildren;
    }

    public function setCanHaveChildren(bool $canHaveChildren): static
    {
        $this->canHaveChildren = $canHaveChildren;

        return $this;
    }


    public function getGroup(): ?StyleGroup
    {
        return $this->group;
    }

    public function setGroup(?StyleGroup $group): static
    {
        $this->group = $group;

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

    public function getRenderTarget(): ?Lookup
    {
        return $this->renderTarget;
    }

    public function setRenderTarget(?Lookup $renderTarget): static
    {
        $this->renderTarget = $renderTarget;

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
            $stylesField->setStyle($this);
        }
        return $this;
    }

    public function removeStylesField(StylesField $stylesField): static
    {
        if ($this->stylesFields->contains($stylesField)) {
            $this->stylesFields->removeElement($stylesField);
            if ($stylesField->getStyle() === $this) {
                $stylesField->setStyle(null);
            }
        }
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, StylesAllowedRelationship>
     */
    public function getAllowedChildrenRelationships(): \Doctrine\Common\Collections\Collection
    {
        return $this->allowedChildrenRelationships;
    }

    public function addAllowedChildrenRelationship(StylesAllowedRelationship $relationship): static
    {
        if (!$this->allowedChildrenRelationships->contains($relationship)) {
            $this->allowedChildrenRelationships->add($relationship);
            $relationship->setParentStyle($this);
        }

        return $this;
    }

    public function removeAllowedChildrenRelationship(StylesAllowedRelationship $relationship): static
    {
        if ($this->allowedChildrenRelationships->removeElement($relationship)) {
            // set the owning side to null (unless already changed)
            if ($relationship->getParentStyle() === $this) {
                $relationship->setParentStyle(null);
            }
        }

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, StylesAllowedRelationship>
     */
    public function getAllowedParentsRelationships(): \Doctrine\Common\Collections\Collection
    {
        return $this->allowedParentsRelationships;
    }

    public function addAllowedParentsRelationship(StylesAllowedRelationship $relationship): static
    {
        if (!$this->allowedParentsRelationships->contains($relationship)) {
            $this->allowedParentsRelationships->add($relationship);
            $relationship->setChildStyle($this);
        }

        return $this;
    }

    public function removeAllowedParentsRelationship(StylesAllowedRelationship $relationship): static
    {
        if ($this->allowedParentsRelationships->removeElement($relationship)) {
            // set the owning side to null (unless already changed)
            if ($relationship->getChildStyle() === $this) {
                $relationship->setChildStyle(null);
            }
        }

        return $this;
    }
}
// ENTITY RULE