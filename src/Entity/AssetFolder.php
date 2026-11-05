<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A folder in the asset library: organisation only, nestable. Access is never
 * decided by folders (see {@see \App\Service\CMS\Admin\AssetAccessService}).
 * Deleting a folder deletes its subfolders; their assets move to the top level.
 */
#[ORM\Entity]
#[ORM\Table(name: 'assets_folders')]
#[ORM\Index(name: 'idx_assets_folders_id_parent', columns: ['id_parent'])]
class AssetFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'id_parent', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?AssetFolder $parent = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getParent(): ?AssetFolder
    {
        return $this->parent;
    }

    public function setParent(?AssetFolder $parent): static
    {
        $this->parent = $parent;

        return $this;
    }
}
