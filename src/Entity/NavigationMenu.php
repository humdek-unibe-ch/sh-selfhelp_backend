<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\NavigationMenuRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NavigationMenuRepository::class)]
#[ORM\Table(name: 'navigation_menus')]
#[ORM\UniqueConstraint(name: 'uq_navigation_menus_id_navigation_menu_key', columns: ['id_navigation_menu_key'])]
class NavigationMenu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_navigation_menu_key', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $menuKey = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_platform', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $platform = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_surface', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $surface = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_preset', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Lookup $preset = null;

    #[ORM\Column(name: 'max_depth', type: 'integer', nullable: true)]
    private ?int $maxDepth = null;

    #[ORM\Column(name: 'item_limit', type: 'integer', nullable: true)]
    private ?int $itemLimit = null;

    /** How web pages present their menu branch (children/siblings); null = platform default. */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_children_nav', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Lookup $childrenNav = null;

    /** Render a breadcrumb trail above nested web pages of this menu. */
    #[ORM\Column(name: 'show_breadcrumbs', type: 'boolean', options: ['default' => 1])]
    private bool $showBreadcrumbs = true;

    /** Render the prev/next pager on nested web pages of this menu. */
    #[ORM\Column(name: 'show_pager', type: 'boolean', options: ['default' => 1])]
    private bool $showPager = true;

    #[ORM\Column(name: 'is_system', type: 'boolean', options: ['default' => 1])]
    private bool $isSystem = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMenuKey(): ?Lookup
    {
        return $this->menuKey;
    }

    public function setMenuKey(?Lookup $menuKey): static
    {
        $this->menuKey = $menuKey;

        return $this;
    }

    public function getPlatform(): ?Lookup
    {
        return $this->platform;
    }

    public function setPlatform(?Lookup $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getSurface(): ?Lookup
    {
        return $this->surface;
    }

    public function setSurface(?Lookup $surface): static
    {
        $this->surface = $surface;

        return $this;
    }

    public function getPreset(): ?Lookup
    {
        return $this->preset;
    }

    public function setPreset(?Lookup $preset): static
    {
        $this->preset = $preset;

        return $this;
    }

    public function getMaxDepth(): ?int
    {
        return $this->maxDepth;
    }

    public function setMaxDepth(?int $maxDepth): static
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    public function getItemLimit(): ?int
    {
        return $this->itemLimit;
    }

    public function setItemLimit(?int $itemLimit): static
    {
        $this->itemLimit = $itemLimit;

        return $this;
    }

    public function getChildrenNav(): ?Lookup
    {
        return $this->childrenNav;
    }

    public function setChildrenNav(?Lookup $childrenNav): static
    {
        $this->childrenNav = $childrenNav;

        return $this;
    }

    public function isShowBreadcrumbs(): bool
    {
        return $this->showBreadcrumbs;
    }

    public function setShowBreadcrumbs(bool $showBreadcrumbs): static
    {
        $this->showBreadcrumbs = $showBreadcrumbs;

        return $this;
    }

    public function isShowPager(): bool
    {
        return $this->showPager;
    }

    public function setShowPager(bool $showPager): static
    {
        $this->showPager = $showPager;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;

        return $this;
    }
}
