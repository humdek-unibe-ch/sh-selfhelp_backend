<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Per-day external referrer host aggregate (site-wide, not per page).
 *
 * The web frontend forwards `document.referrer`'s host on first page load via
 * the `X-Referrer-Host` header; only external hosts are counted. Rows are
 * upserted by {@see \App\Service\CMS\Frontend\PageViewTrackerService}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'page_view_referrers')]
#[ORM\UniqueConstraint(name: 'uq_page_view_referrers_day_host', columns: ['view_date', 'referrer_host'])]
#[ORM\Index(name: 'idx_page_view_referrers_view_date', columns: ['view_date'])]
class PageViewReferrer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'view_date', type: 'date_immutable')]
    private \DateTimeImmutable $viewDate;

    #[ORM\Column(name: 'referrer_host', type: 'string', length: 190)]
    private string $referrerHost = '';

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

    public function getReferrerHost(): string
    {
        return $this->referrerHost;
    }

    public function setReferrerHost(string $referrerHost): self
    {
        $this->referrerHost = $referrerHost;
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
