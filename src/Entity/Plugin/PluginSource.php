<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Entity\Plugin;

use App\Repository\Plugin\PluginSourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plugin source / registry configuration. The CMS may be configured with
 * one or more public/private registries plus git/local sources. The
 * `RegistryClient` walks every enabled source in order to resolve a
 * plugin id to a list of available versions.
 *
 * Secret values are NEVER stored in this table. The column
 * `authSecretEnvVar` records the name of the env variable holding the
 * secret; the runtime reads the secret from the environment.
 */
#[ORM\Entity(repositoryClass: PluginSourceRepository::class)]
#[ORM\Table(name: 'plugin_sources')]
#[ORM\UniqueConstraint(name: 'uq_plugin_sources_name', columns: ['name'])]
#[ORM\Index(name: 'idx_plugin_sources_enabled', columns: ['enabled'])]
#[ORM\Index(name: 'idx_plugin_sources_kind', columns: ['kind'])]
class PluginSource
{
    public const KIND_PUBLIC_REGISTRY = 'public-registry';
    public const KIND_PRIVATE_REGISTRY = 'private-registry';
    public const KIND_GIT = 'git';
    public const KIND_LOCAL = 'local';

    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_BETA = 'beta';
    public const CHANNEL_ALPHA = 'alpha';
    public const CHANNEL_NIGHTLY = 'nightly';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100, options: ['comment' => 'Friendly source name'])]
    private string $name;

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 20, options: ['comment' => 'public-registry | private-registry | git | local'])]
    private string $kind;

    #[ORM\Column(name: 'url', type: Types::STRING, length: 1000)]
    private string $url;

    #[ORM\Column(name: 'auth_header_name', type: Types::STRING, length: 100, nullable: true, options: ['comment' => 'e.g. Authorization or X-Token'])]
    private ?string $authHeaderName = null;

    #[ORM\Column(name: 'auth_secret_env_var', type: Types::STRING, length: 100, nullable: true, options: ['comment' => 'Env var name holding the secret (never the secret itself)'])]
    private ?string $authSecretEnvVar = null;

    #[ORM\Column(name: 'channel', type: Types::STRING, length: 20, options: ['default' => 'stable', 'comment' => 'stable | beta | alpha | nightly'])]
    private string $channel = self::CHANNEL_STABLE;

    #[ORM\Column(name: 'trust_level', type: Types::STRING, length: 20, options: ['default' => 'untrusted', 'comment' => 'official | reviewed | untrusted'])]
    private string $trustLevel = Plugin::TRUST_UNTRUSTED;

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => 1])]
    private bool $enabled = true;

    /**
     * Marks the source as host-managed. System sources are seeded by
     * Doctrine migrations (e.g. the default `humdek-public` registry)
     * and the admin API rejects update/delete requests against them so
     * an operator cannot accidentally break the install pipeline. The
     * UI still allows toggling `enabled` on a system source — operators
     * may disable the upstream feed without removing the row.
     */
    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN, options: ['default' => 0, 'comment' => 'Host-managed source; read-only via admin API.'])]
    private bool $isSystem = false;

    #[ORM\Column(name: 'last_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $kind, string $url)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->url = $url;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getAuthHeaderName(): ?string
    {
        return $this->authHeaderName;
    }

    public function setAuthHeaderName(?string $authHeaderName): self
    {
        $this->authHeaderName = $authHeaderName;
        return $this;
    }

    public function getAuthSecretEnvVar(): ?string
    {
        return $this->authSecretEnvVar;
    }

    public function setAuthSecretEnvVar(?string $authSecretEnvVar): self
    {
        $this->authSecretEnvVar = $authSecretEnvVar;
        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): self
    {
        $this->lastSyncedAt = $lastSyncedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
}
// ENTITY RULE
