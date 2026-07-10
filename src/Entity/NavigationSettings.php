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
#[ORM\Index(name: 'idx_navigation_settings_id_web_header_search_mode', columns: ['id_web_header_search_mode'])]
#[ORM\Index(name: 'idx_navigation_settings_id_search_default_visibility', columns: ['id_search_default_visibility'])]
#[ORM\Index(name: 'idx_navigation_settings_id_search_field_policy', columns: ['id_search_field_policy'])]
#[ORM\Index(name: 'idx_navigation_settings_id_web_guest_start_page', columns: ['id_web_guest_start_page'])]
#[ORM\Index(name: 'idx_navigation_settings_id_web_user_start_page', columns: ['id_web_user_start_page'])]
#[ORM\Index(name: 'idx_navigation_settings_id_web_user_start_mode', columns: ['id_web_user_start_mode'])]
#[ORM\Index(name: 'idx_navigation_settings_id_mobile_guest_start_page', columns: ['id_mobile_guest_start_page'])]
#[ORM\Index(name: 'idx_navigation_settings_id_mobile_user_start_page', columns: ['id_mobile_user_start_page'])]
#[ORM\Index(name: 'idx_navigation_settings_id_mobile_user_start_mode', columns: ['id_mobile_user_start_mode'])]
#[ORM\Index(name: 'idx_navigation_settings_id_mobile_start_page_source', columns: ['id_mobile_start_page_source'])]
#[ORM\Index(name: 'idx_navigation_settings_id_route_sync_old_route_policy', columns: ['id_route_sync_old_route_policy'])]
#[ORM\Index(name: 'idx_navigation_settings_id_logo_link_page', columns: ['id_logo_link_page'])]
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

    /** Public path of the header/drawer logo asset (from the asset library); null = text fallback. */
    #[ORM\Column(name: 'logo_asset_path', type: 'string', length: 500, nullable: true)]
    private ?string $logoAssetPath = null;

    /** Accessible alt text / brand name shown when no logo image is set. */
    #[ORM\Column(name: 'logo_alt', type: 'string', length: 255, nullable: true)]
    private ?string $logoAlt = null;

    /** Page the logo links to; null = the home page. */
    #[ORM\ManyToOne(targetEntity: Page::class)]
    #[ORM\JoinColumn(name: 'id_logo_link_page', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Page $logoLinkPage = null;

    /** Brand block size: sm | md | lg | xl (logo height 24/32/44/56px). */
    #[ORM\Column(name: 'logo_size', type: 'string', length: 8, options: ['default' => 'md'])]
    private string $logoSize = 'md';

    /** Brand block variant: logo-and-name | logo-only | name-only. */
    #[ORM\Column(name: 'logo_variant', type: 'string', length: 16, options: ['default' => 'logo-and-name'])]
    private string $logoVariant = 'logo-and-name';

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

    public function getLogoAssetPath(): ?string
    {
        return $this->logoAssetPath;
    }

    public function setLogoAssetPath(?string $logoAssetPath): static
    {
        $this->logoAssetPath = $logoAssetPath;

        return $this;
    }

    public function getLogoAlt(): ?string
    {
        return $this->logoAlt;
    }

    public function setLogoAlt(?string $logoAlt): static
    {
        $this->logoAlt = $logoAlt;

        return $this;
    }

    public function getLogoLinkPage(): ?Page
    {
        return $this->logoLinkPage;
    }

    public function setLogoLinkPage(?Page $logoLinkPage): static
    {
        $this->logoLinkPage = $logoLinkPage;

        return $this;
    }

    public function getLogoSize(): string
    {
        return $this->logoSize;
    }

    public function setLogoSize(string $logoSize): static
    {
        $this->logoSize = $logoSize;

        return $this;
    }

    public function getLogoVariant(): string
    {
        return $this->logoVariant;
    }

    public function setLogoVariant(string $logoVariant): static
    {
        $this->logoVariant = $logoVariant;

        return $this;
    }
}
