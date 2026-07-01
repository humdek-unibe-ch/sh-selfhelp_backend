<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\NavigationMenuItemExclusionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NavigationMenuItemExclusionRepository::class)]
#[ORM\Table(name: 'navigation_menu_item_exclusions')]
#[ORM\UniqueConstraint(name: 'uq_navigation_menu_item_exclusions_item_page', columns: ['id_navigation_menu_items', 'id_pages'])]
class NavigationMenuItemExclusion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NavigationMenuItem::class)]
    #[ORM\JoinColumn(name: 'id_navigation_menu_items', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?NavigationMenuItem $navigationMenuItem = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNavigationMenuItem(): ?NavigationMenuItem
    {
        return $this->navigationMenuItem;
    }

    public function setNavigationMenuItem(?NavigationMenuItem $navigationMenuItem): static
    {
        $this->navigationMenuItem = $navigationMenuItem;

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
}
