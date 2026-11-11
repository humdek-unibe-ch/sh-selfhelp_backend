<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An uploaded asset. The `uuid` is also its file name on disk, so replacing the
 * file keeps every reference working; `name` is only a label. No groups =
 * public ({@see \App\Service\CMS\Admin\AssetAccessService}).
 */
#[ORM\Entity(repositoryClass: \App\Repository\AssetRepository::class)]
#[ORM\Table(name: 'assets')]
#[ORM\UniqueConstraint(name: 'uq_assets_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_assets_id_asset_types', columns: ['id_asset_types'])]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'uuid', type: 'string', length: 36)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_asset_types', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Lookup $assetType;

    #[ORM\ManyToOne(targetEntity: AssetFolder::class)]
    #[ORM\JoinColumn(name: 'id_assets_folders', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AssetFolder $folder = null;

    #[ORM\Column(name: 'name', type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 150, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size', type: 'integer', nullable: true)]
    private ?int $size = null;

    #[ORM\Column(name: 'alt', type: 'string', length: 500, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'rel_assets_groups')]
    #[ORM\JoinColumn(name: 'id_assets', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'id_groups', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getFolder(): ?AssetFolder
    {
        return $this->folder;
    }

    public function setFolder(?AssetFolder $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

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

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    public function removeGroup(Group $group): static
    {
        $this->groups->removeElement($group);

        return $this;
    }
}
// ENTITY RULE
