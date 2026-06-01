<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;

use App\Repository\ApiRouteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiRouteRepository::class)]
#[ORM\Table(name: 'api_routes')]
#[ORM\UniqueConstraint(name: 'uq_api_routes_version_path_methods', columns: ['version', 'path', 'methods'])]
#[ORM\UniqueConstraint(name: 'uq_api_routes_route_name_version', columns: ['route_name', 'version'])]
#[ORM\Index(name: 'idx_api_routes_id_plugins', columns: ['id_plugins'])]
class ApiRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'route_name', length: 100)]
    private ?string $route_name = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 255)]
    private ?string $controller = null;

    #[ORM\Column(length: 50)]
    private ?string $methods = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $requirements = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['comment' => 'Expected parameters: name → {in: body|query, required: bool}'])]
    private ?array $params = null;

    #[ORM\Column(length: 10, options: ['default' => 'v1'])]
    private ?string $version = 'v1';

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class, inversedBy: 'apiRoutes')]
    #[ORM\JoinTable(name: 'rel_api_routes_permissions',
        joinColumns: [new ORM\JoinColumn(name: 'id_api_routes', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'id_permissions', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $permissions;

    /**
     * Plugin that owns this route row. NULL = core-owned. The
     * ApiRouteLoader filters out routes whose plugin is disabled so
     * disabling a plugin instantly takes its API offline without
     * losing the route definition.
     */
    #[ORM\ManyToOne(targetEntity: \App\Entity\Plugin\Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Plugin\Plugin $plugin = null;
    
    public function __construct()
    {
        $this->permissions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRouteName(): ?string
    {
        return $this->route_name;
    }

    public function setRouteName(string $route_name): static
    {
        $this->route_name = $route_name;

        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getController(): ?string
    {
        return $this->controller;
    }

    public function setController(string $controller): static
    {
        $this->controller = $controller;

        return $this;
    }

    public function getMethods(): ?string
    {
        return $this->methods;
    }

    public function setMethods(string $methods): static
    {
        $this->methods = $methods;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRequirements(): ?array
    {
        return $this->requirements;
    }

    /** @param array<string, mixed>|null $requirements */
    public function setRequirements(?array $requirements): static
    {
        $this->requirements = $requirements;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /** @param array<string, mixed>|null $params */
    public function setParams(?array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Get the permissions associated with this API route
     * 
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    /**
     * Add a permission to this API route
     */
    public function addPermission(Permission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }

        return $this;
    }

    /**
     * Remove a permission from this API route
     */
    public function removePermission(Permission $permission): self
    {
        $this->permissions->removeElement($permission);
        return $this;
    }
}
// ENTITY RULE
