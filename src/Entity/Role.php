<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use App\Entity\Permission;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
#[ORM\UniqueConstraint(name: 'uq_roles_name', columns: ['name'])]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 50)]
    private string $name;

    #[ORM\Column(name: 'description', type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * Full-access role (F10). Replaces matching on the name "admin".
     * The flag cannot be changed through the API; the role cannot be deleted.
     */
    #[ORM\Column(name: 'is_superuser', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSuperuser = false;

    /**
     * Opaque token that changes whenever what the role's holders may do
     * changes (its permissions or data-access grants). A role change bumps this
     * one value instead of every holder's `users.acl_version`; the access stamp
     * (`X-Acl-Stamp`) folds it in.
     */
    #[ORM\Column(name: 'acl_version', type: Types::STRING, length: 36, nullable: true)]
    private ?string $acl_version = null;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class, mappedBy: 'roles')]
    private Collection $permissions;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'roles')]
    private Collection $users;

    public function __construct()
    {
        $this->permissions = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    /**
     * Get the role ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get the role name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the role name
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the role description
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set the role description
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isSuperuser(): bool
    {
        return $this->isSuperuser;
    }

    public function setIsSuperuser(bool $isSuperuser): self
    {
        $this->isSuperuser = $isSuperuser;
        return $this;
    }

    public function getAclVersion(): ?string
    {
        return $this->acl_version;
    }

    public function setAclVersion(?string $aclVersion): self
    {
        $this->acl_version = $aclVersion;
        return $this;
    }

    /** Assign a new acl_version; see the property for why. */
    public function bumpAclVersion(): self
    {
        $this->acl_version = bin2hex(random_bytes(16));
        return $this;
    }

    /**
     * Get the permissions associated with this role
     * 
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    /**
     * Add a permission to this role
     */
    public function addPermission(Permission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
            $permission->addRole($this);
        }

        return $this;
    }

    /**
     * Remove a permission from this role
     */
    public function removePermission(Permission $permission): self
    {
        if ($this->permissions->removeElement($permission)) {
            $permission->removeRole($this);
        }

        return $this;
    }

    /**
     * Get the users associated with this role
     * 
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /**
     * Add a user to this role
     */
    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->addRole($this);
        }

        return $this;
    }

    /**
     * Remove a user from this role
     */
    public function removeUser(User $user): self
    {
        if ($this->users->removeElement($user)) {
            $user->removeRole($this);
        }

        return $this;
    }
}
// ENTITY RULE
