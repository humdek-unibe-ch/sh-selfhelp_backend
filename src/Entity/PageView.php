<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Anonymous per-day page-view aggregate.
 *
 * One row per (day, page, platform, visitor). The visitor hash is a daily
 * rotating HMAC (user id or IP+UA keyed with the app secret and the date), so
 * visitors can never be tracked across days and no PII is stored. Totals are
 * `SUM(views)`; unique visitors are `COUNT(*)` over the grouping of interest.
 *
 * Rows are written by {@see \App\Service\CMS\Frontend\PageViewTrackerService}
 * via an atomic upsert; Doctrine only manages the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_views')]
#[ORM\UniqueConstraint(name: 'uq_page_views_day_page_platform_visitor', columns: ['view_date', 'id_pages', 'platform', 'visitor_hash'])]
#[ORM\Index(name: 'idx_page_views_view_date', columns: ['view_date'])]
#[ORM\Index(name: 'idx_page_views_id_pages', columns: ['id_pages'])]
class PageView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'view_date', type: 'date_immutable')]
    private \DateTimeImmutable $viewDate;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_pages', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Page $page = null;

    /** `web` or `mobile`. */
    #[ORM\Column(name: 'platform', type: 'string', length: 16)]
    private string $platform = 'web';

    /** Daily rotating anonymous visitor fingerprint (32 hex chars). */
    #[ORM\Column(name: 'visitor_hash', type: 'string', length: 32)]
    private string $visitorHash = '';

    #[ORM\Column(name: 'views', type: 'integer', options: ['default' => 1])]
    private int $views = 1;

    public function __construct()
    {
        $this->viewDate = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getViewDate(): \DateTimeImmutable
    {
        return $this->viewDate;
    }

    public function setViewDate(\DateTimeImmutable $viewDate): self
    {
        $this->viewDate = $viewDate;
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

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;
        return $this;
    }

    public function getVisitorHash(): string
    {
        return $this->visitorHash;
    }

    public function setVisitorHash(string $visitorHash): self
    {
        $this->visitorHash = $visitorHash;
        return $this;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function setViews(int $views): self
    {
        $this->views = $views;
        return $this;
    }
}
