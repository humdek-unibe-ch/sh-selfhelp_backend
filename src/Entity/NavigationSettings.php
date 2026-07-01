<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Entity;

use App\Repository\NavigationSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** Singleton navigation settings row (id = 1). */
#[ORM\Entity(repositoryClass: NavigationSettingsRepository::class)]
#[ORM\Table(name: 'navigation_settings')]
class NavigationSettings
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_web_header_search_mode', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $webHeaderSearchMode = null;

    #[ORM\Column(name: 'web_header_search_min_chars', type: 'integer', options: ['default' => 2])]
    private int $webHeaderSearchMinChars = 2;

    #[ORM\Column(name: 'web_header_search_result_limit', type: 'integer', options: ['default' => 8])]
    private int $webHeaderSearchResultLimit = 8;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_search_default_visibility', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $searchDefaultVisibility = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_search_field_policy', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $searchFieldPolicy = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_web_guest_start_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $webGuestStartPage = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_web_user_start_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $webUserStartPage = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_web_user_start_mode', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $webUserStartMode = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_mobile_guest_start_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $mobileGuestStartPage = null;

    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_mobile_user_start_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $mobileUserStartPage = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_mobile_user_start_mode', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $mobileUserStartMode = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_mobile_start_page_source', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $mobileStartPageSource = null;

    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_route_sync_old_route_policy', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $routeSyncOldRoutePolicy = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getWebHeaderSearchMode(): ?Lookup
    {
        return $this->webHeaderSearchMode;
    }

    public function setWebHeaderSearchMode(?Lookup $webHeaderSearchMode): static
    {
        $this->webHeaderSearchMode = $webHeaderSearchMode;

        return $this;
    }

    public function getWebHeaderSearchMinChars(): int
    {
        return $this->webHeaderSearchMinChars;
    }

    public function setWebHeaderSearchMinChars(int $webHeaderSearchMinChars): static
    {
        $this->webHeaderSearchMinChars = $webHeaderSearchMinChars;

        return $this;
    }

    public function getWebHeaderSearchResultLimit(): int
    {
        return $this->webHeaderSearchResultLimit;
    }

    public function setWebHeaderSearchResultLimit(int $webHeaderSearchResultLimit): static
    {
        $this->webHeaderSearchResultLimit = $webHeaderSearchResultLimit;

        return $this;
    }

    public function getSearchDefaultVisibility(): ?Lookup
    {
        return $this->searchDefaultVisibility;
    }

    public function setSearchDefaultVisibility(?Lookup $searchDefaultVisibility): static
    {
        $this->searchDefaultVisibility = $searchDefaultVisibility;

        return $this;
    }

    public function getSearchFieldPolicy(): ?Lookup
    {
        return $this->searchFieldPolicy;
    }

    public function setSearchFieldPolicy(?Lookup $searchFieldPolicy): static
    {
        $this->searchFieldPolicy = $searchFieldPolicy;

        return $this;
    }

    public function getWebGuestStartPage(): ?Page
    {
        return $this->webGuestStartPage;
    }

    public function setWebGuestStartPage(?Page $webGuestStartPage): static
    {
        $this->webGuestStartPage = $webGuestStartPage;

        return $this;
    }

    public function getWebUserStartPage(): ?Page
    {
        return $this->webUserStartPage;
    }

    public function setWebUserStartPage(?Page $webUserStartPage): static
    {
        $this->webUserStartPage = $webUserStartPage;

        return $this;
    }

    public function getWebUserStartMode(): ?Lookup
    {
        return $this->webUserStartMode;
    }

    public function setWebUserStartMode(?Lookup $webUserStartMode): static
    {
        $this->webUserStartMode = $webUserStartMode;

        return $this;
    }

    public function getMobileGuestStartPage(): ?Page
    {
        return $this->mobileGuestStartPage;
    }

    public function setMobileGuestStartPage(?Page $mobileGuestStartPage): static
    {
        $this->mobileGuestStartPage = $mobileGuestStartPage;

        return $this;
    }

    public function getMobileUserStartPage(): ?Page
    {
        return $this->mobileUserStartPage;
    }

    public function setMobileUserStartPage(?Page $mobileUserStartPage): static
    {
        $this->mobileUserStartPage = $mobileUserStartPage;

        return $this;
    }

    public function getMobileUserStartMode(): ?Lookup
    {
        return $this->mobileUserStartMode;
    }

    public function setMobileUserStartMode(?Lookup $mobileUserStartMode): static
    {
        $this->mobileUserStartMode = $mobileUserStartMode;

        return $this;
    }

    public function getMobileStartPageSource(): ?Lookup
    {
        return $this->mobileStartPageSource;
    }

    public function setMobileStartPageSource(?Lookup $mobileStartPageSource): static
    {
        $this->mobileStartPageSource = $mobileStartPageSource;

        return $this;
    }

    public function getRouteSyncOldRoutePolicy(): ?Lookup
    {
        return $this->routeSyncOldRoutePolicy;
    }

    public function setRouteSyncOldRoutePolicy(?Lookup $routeSyncOldRoutePolicy): static
    {
        $this->routeSyncOldRoutePolicy = $routeSyncOldRoutePolicy;

        return $this;
    }
}
