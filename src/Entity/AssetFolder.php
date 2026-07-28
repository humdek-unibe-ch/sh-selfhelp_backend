<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An asset folder as a first-class record, so folder-level settings have a row
 * to live on.
 *
 * `is_open_access` mirrors the page contract ({@see Page::isOpenAccess}): an
 * open-access folder is readable by everyone, including anonymous (not
 * logged-in) callers, and bypasses group ACLs entirely. Closed folders stay
 * closed-by-default — only explicitly granted groups (and the admin role) may
 * read them ({@see \App\Service\CMS\Admin\AssetFolderAclService}).
 *
 * Note that `assets.folder` remains a plain varchar: that column doubles as the
 * on-disk path segment, so it is matched to this table by folder name rather
 * than by FK. The grants in `assets_folders_groups` DO reference this table by
 * FK, so a grant can never point at a folder that does not exist.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AssetFolderRepository::class)]
#[ORM\Table(name: 'assets_folders')]
#[ORM\UniqueConstraint(name: 'uq_assets_folders_folder', columns: ['folder'])]
class AssetFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'folder', type: 'string', length: 100)]
    private string $folder;

    #[ORM\Column(name: 'is_open_access', type: 'boolean', options: ['default' => 0])]
    private bool $isOpenAccess = false;

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

    public function isOpenAccess(): bool
    {
        return $this->isOpenAccess;
    }

    public function setIsOpenAccess(bool $isOpenAccess): static
    {
        $this->isOpenAccess = $isOpenAccess;

        return $this;
    }
}
