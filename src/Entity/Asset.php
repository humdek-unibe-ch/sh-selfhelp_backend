<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assets')]
#[ORM\UniqueConstraint(name: 'uq_assets_file_name', columns: ['file_name'])]
#[ORM\Index(name: 'idx_assets_id_asset_types', columns: ['id_asset_types'])]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_asset_types', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Lookup $assetType;

    #[ORM\Column(name: 'folder', type: 'string', length: 100, nullable: true)]
    private ?string $folder = null;

    #[ORM\Column(name: 'file_name', type: 'string', length: 100, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(name: 'file_path', type: 'string', length: 1000)]
    private string $filePath;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolder(): ?string
    {
        return $this->folder;
    }

    public function setFolder(?string $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getAssetType(): Lookup
    {
        return $this->assetType;
    }

    public function setAssetType(Lookup $assetType): static
    {
        $this->assetType = $assetType;
        return $this;
    }
}
// ENTITY RULE
