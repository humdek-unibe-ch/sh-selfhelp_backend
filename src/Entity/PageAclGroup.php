<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Page-level ACL: per-group "see this page" right against a single page.
 *
 * Groups see; roles edit. A row means the group may see the page and use its
 * forms (`acl_select`). The leftover insert/update/delete bits are gone (F03).
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_acl_groups')]
#[ORM\Index(name: 'idx_page_acl_groups_id_pages', columns: ['id_pages'])]
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

    public function isAclSelect(): ?bool
    {
        return $this->aclSelect;
    }
}
// ENTITY RULE
