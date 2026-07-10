<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Entity;


use App\Repository\PageRouteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A public, parameterized route contract for a CMS page.
 *
 * One {@see Page} can own several patterns (e.g. `/reset` plus
 * `/reset/{user_id}/{token}`). `path_pattern` uses Symfony route syntax
 * (`/team/{record_id}`) and `requirements` maps each placeholder to a regex
 * (`{"record_id":"\\d+"}`). The {@see \App\Routing\PageRouteResolverService}
 * builds a Symfony RouteCollection from the active rows and matches the
 * incoming public path to a page keyword + route params.
 *
 * Global uniqueness of an active `path_pattern` (across pages) plus
 * dynamic-pattern ambiguity is enforced at the service layer by
 * {@see \App\Routing\RouteConflictValidator} — MySQL 8 cannot express an
 * "active rows only" filtered unique index, so the DB only carries the
 * per-page `uq_page_routes_id_pages_path_pattern` guard.
 */
#[ORM\Entity(repositoryClass: PageRouteRepository::class)]
#[ORM\Table(name: 'page_routes')]
#[ORM\UniqueConstraint(name: 'uq_page_routes_id_pages_path_pattern', columns: ['id_pages', 'path_pattern'])]
#[ORM\Index(name: 'idx_page_routes_id_pages', columns: ['id_pages'])]
#[ORM\Index(name: 'idx_page_routes_path_pattern', columns: ['path_pattern'])]
#[ORM\Index(name: 'idx_page_routes_is_active', columns: ['is_active'])]
class PageRoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\Column(name: 'path_pattern', type: 'string', length: 255)]
    private ?string $pathPattern = null;

    /**
     * Placeholder name -> regex requirement, e.g. {"record_id":"\\d+"}.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(name: 'requirements', type: 'json', nullable: true)]
    private ?array $requirements = null;

    #[ORM\Column(name: 'is_canonical', type: 'boolean', options: ['default' => 0])]
    private bool $isCanonical = false;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => 1])]
    private bool $isActive = true;

    #[ORM\Column(name: 'priority', type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPathPattern(): ?string
    {
        return $this->pathPattern;
    }

    public function setPathPattern(string $pathPattern): static
    {
        $this->pathPattern = $pathPattern;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getRequirements(): ?array
    {
        return $this->requirements;
    }

    /** @param array<string, string>|null $requirements */
    public function setRequirements(?array $requirements): static
    {
        $this->requirements = $requirements;

        return $this;
    }

    public function isCanonical(): bool
    {
        return $this->isCanonical;
    }

    public function setIsCanonical(bool $isCanonical): static
    {
        $this->isCanonical = $isCanonical;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
// ENTITY RULE
