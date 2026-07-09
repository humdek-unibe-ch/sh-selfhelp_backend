<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CmsAppRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * First-class CMS-in-CMS application shell.
 *
 * Groups related pages (form / cms list / cms detail / public list / public detail)
 * via {@see Page::$cmsApp}. Hub FKs are denormalized shortcuts maintained ONLY by
 * {@see \App\Service\CMS\Admin\CmsAppHubSyncService}.
 */
#[ORM\Entity(repositoryClass: CmsAppRepository::class)]
#[ORM\Table(name: 'cms_apps')]
#[ORM\UniqueConstraint(name: 'uq_cms_apps_slug', columns: ['slug'])]
class CmsApp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 150)]
    private string $name = '';

    #[ORM\Column(name: 'slug', type: 'string', length: 100)]
    private string $slug = '';

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'id_form_section', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Section $formSection = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_cms_list_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $cmsListPage = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_cms_detail_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $cmsDetailPage = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_public_list_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $publicListPage = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_public_detail_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $publicDetailPage = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getFormSection(): ?Section
    {
        return $this->formSection;
    }

    public function setFormSection(?Section $formSection): static
    {
        $this->formSection = $formSection;

        return $this;
    }

    public function getCmsListPage(): ?Page
    {
        return $this->cmsListPage;
    }

    public function setCmsListPage(?Page $cmsListPage): static
    {
        $this->cmsListPage = $cmsListPage;

        return $this;
    }

    public function getCmsDetailPage(): ?Page
    {
        return $this->cmsDetailPage;
    }

    public function setCmsDetailPage(?Page $cmsDetailPage): static
    {
        $this->cmsDetailPage = $cmsDetailPage;

        return $this;
    }

    public function getPublicListPage(): ?Page
    {
        return $this->publicListPage;
    }

    public function setPublicListPage(?Page $publicListPage): static
    {
        $this->publicListPage = $publicListPage;

        return $this;
    }

    public function getPublicDetailPage(): ?Page
    {
        return $this->publicDetailPage;
    }

    public function setPublicDetailPage(?Page $publicDetailPage): static
    {
        $this->publicDetailPage = $publicDetailPage;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
