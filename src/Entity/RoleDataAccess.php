<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Role Data Access Entity
 *
 * Stores custom data access permissions for roles
 * Implements role-based data access control with bitwise permissions
 */
#[ORM\Entity]
#[ORM\Table(name: 'role_data_access')]
#[ORM\Index(name: 'IDX_role_data_access_roles', columns: ['id_roles'])]
#[ORM\Index(name: 'IDX_role_data_access_resource_types', columns: ['id_resourceTypes'])]
#[ORM\Index(name: 'IDX_role_data_access_resource_id', columns: ['resource_id'])]
#[ORM\Index(name: 'IDX_role_data_access_permissions', columns: ['crud_permissions'])]
#[ORM\UniqueConstraint(name: 'unique_role_resource', columns: ['id_roles', 'id_resourceTypes', 'resource_id'])]
class RoleDataAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'id_roles', type: Types::INTEGER)]
    private int $idRoles;

    #[ORM\Column(name: 'id_resourceTypes', type: Types::INTEGER)]
    private int $idResourceTypes;

    #[ORM\Column(name: 'resource_id', type: Types::INTEGER)]
    private int $resourceId;

    #[ORM\Column(name: 'crud_permissions', type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 2])]
    private int $crudPermissions = 2; // Default to read-only

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    // Relationships
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'id_roles', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_resourceTypes', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Lookup $resourceType;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdRoles(): int
    {
        return $this->idRoles;
    }

    public function getIdResourceTypes(): int
    {
        return $this->idResourceTypes;
    }

    public function getResourceId(): int
    {
        return $this->resourceId;
    }

    public function setResourceId(int $resourceId): self
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getCrudPermissions(): int
    {
        return $this->crudPermissions;
    }

    public function setCrudPermissions(int $crudPermissions): self
    {
        $this->crudPermissions = $crudPermissions;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        // Ensure UTC storage
        $this->createdAt = $createdAt instanceof \DateTimeImmutable
            ? ($createdAt->getTimezone()->getName() === 'UTC' ? $createdAt : $createdAt->setTimezone(new \DateTimeZone('UTC')))
            : \DateTimeImmutable::createFromMutable(
                $createdAt->getTimezone()->getName() === 'UTC'
                    ? $createdAt
                    : $createdAt->setTimezone(new \DateTimeZone('UTC'))
            );
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        // Ensure UTC storage
        $this->updatedAt = $updatedAt instanceof \DateTimeImmutable
            ? ($updatedAt->getTimezone()->getName() === 'UTC' ? $updatedAt : $updatedAt->setTimezone(new \DateTimeZone('UTC')))
            : \DateTimeImmutable::createFromMutable(
                $updatedAt->getTimezone()->getName() === 'UTC'
                    ? $updatedAt
                    : $updatedAt->setTimezone(new \DateTimeZone('UTC'))
            );
        return $this;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getResourceType(): Lookup
    {
        return $this->resourceType;
    }

    public function setResourceType(Lookup $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    /**
     * Check if the permission has a specific CRUD bit set
     */
    public function hasPermission(int $permission): bool
    {
        return ($this->crudPermissions & $permission) === $permission;
    }

    /**
     * Add a permission bit
     */
    public function addPermission(int $permission): self
    {
        $this->crudPermissions |= $permission;
        return $this;
    }

    /**
     * Remove a permission bit
     */
    public function removePermission(int $permission): self
    {
        $this->crudPermissions &= ~$permission;
        return $this;
    }

    /**
     * Set specific permissions (replaces all)
     */
    public function setPermissions(int $permissions): self
    {
        $this->crudPermissions = $permissions;
        return $this;
    }

    /**
     * Get permissions as array of bit flags
     */
    public function getPermissionsArray(): array
    {
        $permissions = [];
        $bits = [1 => 'CREATE', 2 => 'READ', 4 => 'UPDATE', 8 => 'DELETE'];

        foreach ($bits as $bit => $name) {
            if ($this->hasPermission($bit)) {
                $permissions[] = $bit;
            }
        }

        return $permissions;
    }
}
