<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Folder-level access control entry: grants one group a read/manage access
 * level over every asset stored under a given folder string.
 *
 * A folder with no rows is open to all admins (backward compatible). Folder is
 * a plain string (assets carry `folder` as a nullable varchar, not an FK), so
 * this is a first-class entity keyed by (folder, id_groups), not a pure
 * two-FK relation table.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AssetFolderGroupRepository::class)]
#[ORM\Table(name: 'assets_folders_groups')]
#[ORM\UniqueConstraint(name: 'uq_assets_folders_groups_folder_group', columns: ['folder', 'id_groups'])]
#[ORM\Index(name: 'idx_assets_folders_groups_folder', columns: ['folder'])]
class AssetFolderGroup
{
    public const ACCESS_READ = 'read';
    public const ACCESS_MANAGE = 'manage';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'folder', type: 'string', length: 100)]
    private string $folder;

    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'id_groups', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Group $group;

    #[ORM\Column(name: 'access_level', type: 'string', length: 20)]
    private string $accessLevel = self::ACCESS_READ;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function setFolder(string $folder): static
    {
        $this->folder = $folder;

        return $this;
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

    public function getAccessLevel(): string
    {
        return $this->accessLevel;
    }

    public function setAccessLevel(string $accessLevel): static
    {
        $this->accessLevel = $accessLevel;

        return $this;
    }
}
