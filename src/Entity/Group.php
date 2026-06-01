<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: '`groups`')]
class Group
{
    /** @var \Doctrine\Common\Collections\Collection<int, UsersGroup> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: UsersGroup::class, orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $usersGroups;

    /** @var \Doctrine\Common\Collections\Collection<int, ValidationCode> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: ValidationCode::class, orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $validationCodes;



    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'string', length: 250)]
    private string $description;

    #[ORM\Column(name: 'id_group_types', type: 'integer', nullable: true)]
    private ?int $idGroupTypes = null;

    #[ORM\Column(name: 'requires_2fa', type: 'boolean')]
    private bool $requires2fa = false;

    public function __construct()
    {
        $this->usersGroups = new \Doctrine\Common\Collections\ArrayCollection();
        $this->validationCodes = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, UsersGroup>
     */
    public function getUsersGroups(): \Doctrine\Common\Collections\Collection
    {
        return $this->usersGroups;
    }

    public function addUsersGroup(UsersGroup $usersGroup): self
    {
        if (!$this->usersGroups->contains($usersGroup)) {
            $this->usersGroups[] = $usersGroup;
            $usersGroup->setGroup($this);
        }
        return $this;
    }

    public function removeUsersGroup(UsersGroup $usersGroup): self
    {
        if ($this->usersGroups->removeElement($usersGroup)) {
            if ($usersGroup->getGroup() === $this) {
                $usersGroup->setGroup(null);
            }
        }
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, User>
     */
    public function getUsers(): \Doctrine\Common\Collections\Collection
    {
        // Every UsersGroup still in the collection has a non-null user
        // (removeUsersGroup() detaches it before nulling the back-reference);
        // array_filter drops the never-occurring null case to satisfy types.
        return new \Doctrine\Common\Collections\ArrayCollection(
            array_values(array_filter(
                array_map(fn(UsersGroup $ug) => $ug->getUser(), $this->usersGroups->toArray())
            ))
        );
    } // Returns users only via UsersGroup entity. No direct $users property.


    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getIdGroupTypes(): ?int
    {
        return $this->idGroupTypes;
    }

    public function setIdGroupTypes(?int $idGroupTypes): static
    {
        $this->idGroupTypes = $idGroupTypes;

        return $this;
    }

    public function isRequires2fa(): ?bool
    {
        return $this->requires2fa;
    }

    public function setRequires2fa(bool $requires2fa): static
    {
        $this->requires2fa = $requires2fa;

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, ValidationCode>
     */
    public function getValidationCodes(): \Doctrine\Common\Collections\Collection
    {
        return $this->validationCodes;
    }

    public function addValidationCode(ValidationCode $validationCode): self
    {
        if (!$this->validationCodes->contains($validationCode)) {
            $this->validationCodes[] = $validationCode;
            $validationCode->setGroup($this);
        }
        return $this;
    }

    public function removeValidationCode(ValidationCode $validationCode): self
    {
        if ($this->validationCodes->removeElement($validationCode)) {
            if ($validationCode->getGroup() === $this) {
                $validationCode->setGroup(null);
            }
        }
        return $this;
    }
}
// ENTITY RULE

