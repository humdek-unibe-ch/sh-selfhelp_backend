<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rel_sections_navigation')]
class SectionsNavigation
{

    #[ORM\Column(name: 'position', type: 'integer')]
    private int $position;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'id_parent_section', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Section $parentSection = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'id_child_section', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Section $childSection = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getParentSection(): ?Section
    {
        return $this->parentSection;
    }

    public function setParentSection(?Section $parentSection): static
    {
        $this->parentSection = $parentSection;

        return $this;
    }

    public function getChildSection(): ?Section
    {
        return $this->childSection;
    }

    public function setChildSection(?Section $childSection): static
    {
        $this->childSection = $childSection;

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
// ENTITY RULE
