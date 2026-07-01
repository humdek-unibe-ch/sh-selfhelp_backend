<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\PageSearchIndexRepository;
use Doctrine\ORM\Mapping as ORM;

/** Per-page, per-language searchable text projection for public content search. */
#[ORM\Entity(repositoryClass: PageSearchIndexRepository::class)]
#[ORM\Table(name: 'page_search_index')]
#[ORM\UniqueConstraint(name: 'uq_page_search_index_page_lang', columns: ['id_pages', 'id_languages'])]
#[ORM\Index(name: 'idx_page_search_index_id_languages', columns: ['id_languages'])]
class PageSearchIndex
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(name: 'id_languages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Language $language = null;

    #[ORM\Column(name: 'title_text', type: 'text', nullable: true)]
    private ?string $titleText = null;

    #[ORM\Column(name: 'description_text', type: 'text', nullable: true)]
    private ?string $descriptionText = null;

    #[ORM\Column(name: 'body_text', type: 'text', nullable: true)]
    private ?string $bodyText = null;

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

    public function getPage(): ?Page
    {
        return $this->page;
    }

    public function setPage(?Page $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function setLanguage(?Language $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getTitleText(): ?string
    {
        return $this->titleText;
    }

    public function setTitleText(?string $titleText): static
    {
        $this->titleText = $titleText;
        $this->touch();

        return $this;
    }

    public function getDescriptionText(): ?string
    {
        return $this->descriptionText;
    }

    public function setDescriptionText(?string $descriptionText): static
    {
        $this->descriptionText = $descriptionText;
        $this->touch();

        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): static
    {
        $this->bodyText = $bodyText;
        $this->touch();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
