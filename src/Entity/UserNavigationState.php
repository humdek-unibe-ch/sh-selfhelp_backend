<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\UserNavigationStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserNavigationStateRepository::class)]
#[ORM\Table(name: 'user_navigation_state')]
#[ORM\UniqueConstraint(name: 'uq_user_navigation_state_user_platform', columns: ['id_users', 'id_platform'])]
class UserNavigationState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_platform', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $platform = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\Column(name: 'url_snapshot', type: 'string', length: 255, nullable: true)]
    private ?string $urlSnapshot = null;

    #[ORM\Column(name: 'keyword_snapshot', type: 'string', length: 100, nullable: true)]
    private ?string $keywordSnapshot = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPlatform(): ?Lookup
    {
        return $this->platform;
    }

    public function setPlatform(?Lookup $platform): static
    {
        $this->platform = $platform;

        return $this;
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

    public function getUrlSnapshot(): ?string
    {
        return $this->urlSnapshot;
    }

    public function setUrlSnapshot(?string $urlSnapshot): static
    {
        $this->urlSnapshot = $urlSnapshot;

        return $this;
    }

    public function getKeywordSnapshot(): ?string
    {
        return $this->keywordSnapshot;
    }

    public function setKeywordSnapshot(?string $keywordSnapshot): static
    {
        $this->keywordSnapshot = $keywordSnapshot;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
