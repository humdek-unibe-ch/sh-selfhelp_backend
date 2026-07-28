<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Folder-level access control entry: grants one group a read/manage access
 * level over every asset stored under a given folder.
 *
 * A folder with no rows is readable by admins only (closed-by-default), unless
 * it is flagged open-access ({@see AssetFolder::isOpenAccess}). The folder is
 * referenced by FK, so a grant can never point at a folder that does not exist.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AssetFolderGroupRepository::class)]
#[ORM\Table(name: 'assets_folders_groups')]
#[ORM\UniqueConstraint(name: 'uq_assets_folders_groups_folder_group', columns: ['id_assets_folders', 'id_groups'])]
class AssetFolderGroup
{
    /**
     * Access-level lookup codes (`assetFolderAccessLevels`). These mirror
     * {@see \App\Service\Core\LookupService::ASSET_FOLDER_ACCESS_LEVELS_READ}
     * and are the values persisted as `lookups.lookup_code`, not free text:
     * the level itself is stored as an FK so an unknown value cannot exist.
     */
    public const ACCESS_READ = 'read';
    public const ACCESS_MANAGE = 'manage';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssetFolder::class)]
    #[ORM\JoinColumn(name: 'id_assets_folders', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AssetFolder $assetFolder;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'id_groups', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Group $group;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_access_level', referencedColumnName: 'id', nullable: false)]
    private Lookup $accessLevel;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssetFolder(): AssetFolder
    {
        return $this->assetFolder;
    }

    public function setAssetFolder(AssetFolder $assetFolder): static
    {
        $this->assetFolder = $assetFolder;

        return $this;
    }

    /**
     * The folder name this grant applies to, read through the FK.
     */
    public function getFolder(): string
    {
        return $this->assetFolder->getFolder();
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function setGroup(Group $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getAccessLevelLookup(): Lookup
    {
        return $this->accessLevel;
    }

    public function setAccessLevelLookup(Lookup $accessLevel): static
    {
        $this->accessLevel = $accessLevel;

        return $this;
    }

    /**
     * The access-level code (`read` | `manage`), read through the FK. This is
     * what the API emits and what the enforcement layer compares against.
     */
    public function getAccessLevel(): string
    {
        return (string) $this->accessLevel->getLookupCode();
    }
}
