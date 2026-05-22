<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: "App\Repository\LookupRepository")]
#[ORM\Table(name: 'lookups')]
#[ORM\UniqueConstraint(name: 'uq_lookups_type_code_lookup_code', columns: ['type_code', 'lookup_code'])]
#[ORM\Index(name: 'idx_lookups_id_plugins', columns: ['id_plugins'])]
class Lookup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'type_code', type: 'string', length: 100)]
    private string $typeCode = '';

    #[ORM\Column(name: 'lookup_code', type: 'string', length: 100, nullable: true)]
    private ?string $lookupCode = null;

    #[ORM\Column(name: 'lookup_value', type: 'string', length: 200, nullable: true)]
    private ?string $lookupValue = null;

    #[ORM\Column(name: 'lookup_description', type: 'string', length: 500, nullable: true)]
    private ?string $lookupDescription = null;

    /**
     * Plugin that owns this lookup row. NULL = core-owned.
     * The lookup-extension policy (closed / plugin_extendable /
     * plugin_owned) is enforced by LookupExtensionPolicy.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;




    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTypeCode(): string
    {
        if (empty($this->typeCode)) {
            throw new \LogicException('TypeCode must be set before accessing');
        }
        return $this->typeCode;
    }

    public function setTypeCode(string $typeCode): static
    {
        $this->typeCode = $typeCode;
        return $this;
    }

    public function getLookupCode(): ?string
    {
        return $this->lookupCode;
    }

    public function setLookupCode(?string $lookupCode): static
    {
        $this->lookupCode = $lookupCode;
        return $this;
    }

    public function getLookupValue(): ?string
    {
        return $this->lookupValue;
    }

    public function setLookupValue(?string $lookupValue): static
    {
        $this->lookupValue = $lookupValue;
        return $this;
    }

    public function getLookupDescription(): ?string
    {
        return $this->lookupDescription;
    }

    public function setLookupDescription(?string $lookupDescription): static
    {
        $this->lookupDescription = $lookupDescription;
        return $this;
    }

    public function getPlugin(): ?\App\Entity\Plugin\Plugin
    {
        return $this->plugin;
    }

    public function setPlugin(?\App\Entity\Plugin\Plugin $plugin): static
    {
        $this->plugin = $plugin;
        return $this;
    }
}
