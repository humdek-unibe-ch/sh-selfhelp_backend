<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rel_fields_page_types')]
#[ORM\Index(name: 'idx_rel_fields_page_types_id_fields', columns: ['id_fields'])]
class PageTypeField
{

    #[ORM\Column(name: 'default_value', type: 'text', nullable: true)]
    private ?string $defaultValue = null;

    #[ORM\Column(name: 'help', type: 'text', nullable: true)]
    private ?string $help = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PageType::class)]
    #[ORM\JoinColumn(name: 'id_page_types', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?PageType $pageType = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Field::class)]
    #[ORM\JoinColumn(name: 'id_fields', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Field $field = null;

    #[ORM\Column(name: 'title', type: 'string', length: 255, nullable: true)]
    private ?string $title = null;

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;

        return $this;
    }

    public function getHelp(): ?string
    {
        return $this->help;
    }

    public function setHelp(?string $help): static
    {
        $this->help = $help;

        return $this;
    }

    public function getPageType(): ?PageType
    {
        return $this->pageType;
    }

    public function setPageType(?PageType $pageType): static
    {
        $this->pageType = $pageType;

        return $this;
    }

    public function getField(): ?Field
    {
        return $this->field;
    }

    public function setField(?Field $field): static
    {
        $this->field = $field;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }
}
// ENTITY RULE
