<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\NavigationMenuItemTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NavigationMenuItemTranslationRepository::class)]
#[ORM\Table(name: 'navigation_menu_item_translations')]
#[ORM\Index(name: 'idx_navigation_menu_item_translations_id_navigation_menu_items', columns: ['id_navigation_menu_items'])]
#[ORM\Index(name: 'idx_navigation_menu_item_translations_id_languages', columns: ['id_languages'])]
#[ORM\UniqueConstraint(name: 'uq_navigation_menu_item_translations_item_lang', columns: ['id_navigation_menu_items', 'id_languages'])]
class NavigationMenuItemTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: NavigationMenuItem::class)]
    #[ORM\JoinColumn(name: 'id_navigation_menu_items', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?NavigationMenuItem $menuItem = null;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(name: 'id_languages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Language $language = null;

    #[ORM\Column(name: 'label', type: 'string', length: 255, nullable: true)]
    private ?string $label = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMenuItem(): ?NavigationMenuItem
    {
        return $this->menuItem;
    }

    public function setMenuItem(?NavigationMenuItem $menuItem): static
    {
        $this->menuItem = $menuItem;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
