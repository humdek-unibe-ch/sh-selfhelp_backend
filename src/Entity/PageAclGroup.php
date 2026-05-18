<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Page-level ACL: per-group CRUD rights against a single page.
 *
 * Renamed from `AclGroup` (legacy `acl_groups` table) to
 * `PageAclGroup` (`page_acl_groups`) under the strict_split policy:
 * because this join table carries the four boolean CRUD columns it is
 * a first-class domain entity, not a pure relation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_acl_groups')]
class PageAclGroup
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Group::class)]
    #[ORM\JoinColumn(name: 'id_groups', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Group $group = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\Column(name: 'acl_select', type: 'boolean', options: ['default' => 1])]
    private bool $aclSelect = true;

    #[ORM\Column(name: 'acl_insert', type: 'boolean', options: ['default' => 0])]
    private bool $aclInsert = false;

    #[ORM\Column(name: 'acl_update', type: 'boolean', options: ['default' => 0])]
    private bool $aclUpdate = false;

    #[ORM\Column(name: 'acl_delete', type: 'boolean', options: ['default' => 0])]
    private bool $aclDelete = false;

    public function getGroup(): ?Group
    {
        return $this->group;
    }
    public function setGroup(?Group $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }
    public function setPage(?Page $page): self
    {
        $this->page = $page;
        return $this;
    }

    public function getAclSelect(): bool { return $this->aclSelect; }

    public function setAclSelect(bool $aclSelect): static
    {
        $this->aclSelect = $aclSelect;

        return $this;
    }

    public function getAclInsert(): bool { return $this->aclInsert; }

    public function setAclInsert(bool $aclInsert): static
    {
        $this->aclInsert = $aclInsert;

        return $this;
    }

    public function getAclUpdate(): bool { return $this->aclUpdate; }

    public function setAclUpdate(bool $aclUpdate): static
    {
        $this->aclUpdate = $aclUpdate;

        return $this;
    }

    public function getAclDelete(): bool { return $this->aclDelete; }

    public function setAclDelete(bool $aclDelete): static
    {
        $this->aclDelete = $aclDelete;

        return $this;
    }

    public function isAclSelect(): ?bool
    {
        return $this->aclSelect;
    }

    public function isAclInsert(): ?bool
    {
        return $this->aclInsert;
    }

    public function isAclUpdate(): ?bool
    {
        return $this->aclUpdate;
    }

    public function isAclDelete(): ?bool
    {
        return $this->aclDelete;
    }
}
// ENTITY RULE
