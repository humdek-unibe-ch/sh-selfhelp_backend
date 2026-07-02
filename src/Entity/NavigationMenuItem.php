<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\NavigationMenuItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NavigationMenuItemRepository::class)]
#[ORM\Table(name: 'navigation_menu_items')]
#[ORM\Index(name: 'idx_navigation_menu_items_id_navigation_menus', columns: ['id_navigation_menus'])]
#[ORM\Index(name: 'idx_navigation_menu_items_id_parent_item', columns: ['id_parent_item'])]
#[ORM\Index(name: 'idx_navigation_menu_items_id_pages', columns: ['id_pages'])]
class NavigationMenuItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NavigationMenu::class)]
    #[ORM\JoinColumn(name: 'id_navigation_menus', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?NavigationMenu $navigationMenu = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'id_parent_item', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?NavigationMenuItem $parentItem = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_item_type', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $itemType = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\Column(name: 'external_url', type: 'string', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(name: 'icon', type: 'string', length: 100, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'mobile_icon', type: 'string', length: 100, nullable: true)]
    private ?string $mobileIcon = null;

    #[ORM\Column(name: 'label', type: 'string', length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'position', type: 'integer')]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_child_source', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $childSource = null;

    #[ORM\Column(name: 'auto_include_depth', type: 'integer', nullable: true)]
    private ?int $autoIncludeDepth = 1;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => 1])]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNavigationMenu(): ?NavigationMenu
    {
        return $this->navigationMenu;
    }

    public function setNavigationMenu(?NavigationMenu $navigationMenu): static
    {
        $this->navigationMenu = $navigationMenu;

        return $this;
    }

    public function getParentItem(): ?NavigationMenuItem
    {
        return $this->parentItem;
    }

    public function setParentItem(?NavigationMenuItem $parentItem): static
    {
        $this->parentItem = $parentItem;

        return $this;
    }

    public function getItemType(): ?Lookup
    {
        return $this->itemType;
    }

    public function setItemType(?Lookup $itemType): static
    {
        $this->itemType = $itemType;

        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getMobileIcon(): ?string
    {
        return $this->mobileIcon;
    }

    public function setMobileIcon(?string $mobileIcon): static
    {
        $this->mobileIcon = $mobileIcon;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getChildSource(): ?Lookup
    {
        return $this->childSource;
    }

    public function setChildSource(?Lookup $childSource): static
    {
        $this->childSource = $childSource;

        return $this;
    }

    public function getAutoIncludeDepth(): ?int
    {
        return $this->autoIncludeDepth;
    }

    public function setAutoIncludeDepth(?int $autoIncludeDepth): static
    {
        $this->autoIncludeDepth = $autoIncludeDepth;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
