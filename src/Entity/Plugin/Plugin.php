<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Entity\Plugin;

use App\Repository\Plugin\PluginRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Installed plugin record. One row per plugin id.
 *
 * Stable contract:
 *   - `pluginId` is the manifest id (kebab-case, e.g. `sh2-shp-survey-js`).
 *   - `manifestJson` holds the cached full plugin.json so we never have to
 *     re-read the manifest from disk for hot paths.
 *   - `capabilitiesJson` is the capability set granted by the installer
 *     at install time. Runtime guards refuse operations whose capability
 *     is not present.
 */
#[ORM\Entity(repositoryClass: PluginRepository::class)]
#[ORM\Table(name: 'plugins')]
#[ORM\UniqueConstraint(name: 'uq_plugins_plugin_id', columns: ['plugin_id'])]
#[ORM\Index(name: 'idx_plugins_enabled', columns: ['enabled'])]
#[ORM\Index(name: 'idx_plugins_trust_level', columns: ['trust_level'])]
class Plugin
{
    public const TRUST_OFFICIAL = 'official';
    public const TRUST_REVIEWED = 'reviewed';
    public const TRUST_UNTRUSTED = 'untrusted';

    public const INSTALL_MODE_DEVELOPMENT = 'development';
    public const INSTALL_MODE_MANAGED = 'managed';
    public const INSTALL_MODE_TRUSTED = 'trusted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'plugin_id', type: Types::STRING, length: 100, options: ['comment' => 'Plugin manifest id, e.g. sh2-shp-survey-js'])]
    private string $pluginId;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(name: 'description', type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'version', type: Types::STRING, length: 50)]
    private string $version;

    #[ORM\Column(name: 'plugin_api_version', type: Types::STRING, length: 20)]
    private string $pluginApiVersion;

    #[ORM\Column(name: 'trust_level', type: Types::STRING, length: 20, options: ['default' => 'untrusted', 'comment' => 'official | reviewed | untrusted'])]
    private string $trustLevel = self::TRUST_UNTRUSTED;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => 0])]
    private bool $enabled = false;

    #[ORM\Column(name: 'install_mode', type: Types::STRING, length: 20, options: ['default' => 'managed', 'comment' => 'development | managed | trusted'])]
    private string $installMode = self::INSTALL_MODE_MANAGED;

    #[ORM\Column(name: 'backend_package', type: Types::STRING, length: 255, nullable: true)]
    private ?string $backendPackage = null;

    #[ORM\Column(name: 'backend_bundle_class', type: Types::STRING, length: 255, nullable: true)]
    private ?string $backendBundleClass = null;

    #[ORM\Column(name: 'frontend_package', type: Types::STRING, length: 255, nullable: true)]
    private ?string $frontendPackage = null;

    #[ORM\Column(name: 'frontend_package_version', type: Types::STRING, length: 50, nullable: true)]
    private ?string $frontendPackageVersion = null;

    #[ORM\Column(name: 'mobile_package', type: Types::STRING, length: 255, nullable: true)]
    private ?string $mobilePackage = null;

    #[ORM\Column(name: 'mobile_package_version', type: Types::STRING, length: 50, nullable: true)]
    private ?string $mobilePackageVersion = null;

    /** @var array<string,mixed> */
    #[ORM\Column(name: 'manifest_json', type: Types::JSON, options: ['comment' => 'Cached full plugin.json'])]
    private array $manifestJson = [];

    /** @var array<int,string> */
    #[ORM\Column(name: 'capabilities_json', type: Types::JSON, options: ['comment' => 'Granted capabilities at install time'])]
    private array $capabilitiesJson = [];

    #[ORM\Column(name: 'checksum_sha256', type: Types::STRING, length: 128, nullable: true)]
    private ?string $checksumSha256 = null;

    #[ORM\Column(name: 'signature_ed25519', type: Types::STRING, length: 512, nullable: true)]
    private ?string $signatureEd25519 = null;

    #[ORM\Column(name: 'installed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $installedAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'enabled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    #[ORM\Column(name: 'disabled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(string $pluginId, string $name, string $version, string $pluginApiVersion)
    {
        $this->pluginId = $pluginId;
        $this->name = $name;
        $this->version = $version;
        $this->pluginApiVersion = $pluginApiVersion;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->installedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getPluginApiVersion(): string
    {
        return $this->pluginApiVersion;
    }

    public function setPluginApiVersion(string $pluginApiVersion): self
    {
        $this->pluginApiVersion = $pluginApiVersion;
        return $this;
    }

    public function getTrustLevel(): string
    {
        return $this->trustLevel;
    }

    public function setTrustLevel(string $trustLevel): self
    {
        $this->trustLevel = $trustLevel;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getInstallMode(): string
    {
        return $this->installMode;
    }

    public function setInstallMode(string $installMode): self
    {
        $this->installMode = $installMode;
        return $this;
    }

    public function getBackendPackage(): ?string
    {
        return $this->backendPackage;
    }

    public function setBackendPackage(?string $backendPackage): self
    {
        $this->backendPackage = $backendPackage;
        return $this;
    }

    public function getBackendBundleClass(): ?string
    {
        return $this->backendBundleClass;
    }

    public function setBackendBundleClass(?string $backendBundleClass): self
    {
        $this->backendBundleClass = $backendBundleClass;
        return $this;
    }

    public function getFrontendPackage(): ?string
    {
        return $this->frontendPackage;
    }

    public function setFrontendPackage(?string $frontendPackage): self
    {
        $this->frontendPackage = $frontendPackage;
        return $this;
    }

    public function getFrontendPackageVersion(): ?string
    {
        return $this->frontendPackageVersion;
    }

    public function setFrontendPackageVersion(?string $frontendPackageVersion): self
    {
        $this->frontendPackageVersion = $frontendPackageVersion;
        return $this;
    }

    public function getMobilePackage(): ?string
    {
        return $this->mobilePackage;
    }

    public function setMobilePackage(?string $mobilePackage): self
    {
        $this->mobilePackage = $mobilePackage;
        return $this;
    }

    public function getMobilePackageVersion(): ?string
    {
        return $this->mobilePackageVersion;
    }

    public function setMobilePackageVersion(?string $mobilePackageVersion): self
    {
        $this->mobilePackageVersion = $mobilePackageVersion;
        return $this;
    }

    /** @return array<string,mixed> */
    public function getManifestJson(): array
    {
        return $this->manifestJson;
    }

    /** @param array<string,mixed> $manifestJson */
    public function setManifestJson(array $manifestJson): self
    {
        $this->manifestJson = $manifestJson;
        return $this;
    }

    /** @return array<int,string> */
    public function getCapabilitiesJson(): array
    {
        return $this->capabilitiesJson;
    }

    /** @param array<int,string> $capabilitiesJson */
    public function setCapabilitiesJson(array $capabilitiesJson): self
    {
        $this->capabilitiesJson = $capabilitiesJson;
        return $this;
    }

    public function getChecksumSha256(): ?string
    {
        return $this->checksumSha256;
    }

    public function setChecksumSha256(?string $checksumSha256): self
    {
        $this->checksumSha256 = $checksumSha256;
        return $this;
    }

    public function getSignatureEd25519(): ?string
    {
        return $this->signatureEd25519;
    }

    public function setSignatureEd25519(?string $signatureEd25519): self
    {
        $this->signatureEd25519 = $signatureEd25519;
        return $this;
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getEnabledAt(): ?\DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function setEnabledAt(?\DateTimeImmutable $enabledAt): self
    {
        $this->enabledAt = $enabledAt;
        return $this;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function setDisabledAt(?\DateTimeImmutable $disabledAt): self
    {
        $this->disabledAt = $disabledAt;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilitiesJson, true);
    }
}
// ENTITY RULE
