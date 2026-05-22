<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Entity\Plugin;

use App\Entity\User;
use App\Repository\Plugin\PluginFeatureFlagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Feature flag toggle for a plugin.
 *
 * Scope semantics:
 *   - `scope='global', scopeValue=''` — server-wide flag.
 *   - `scope='role',   scopeValue=<roleId>`  — per-role.
 *   - `scope='user',   scopeValue=<userId>`  — per-user.
 *   - `scope='group',  scopeValue=<groupId>` — per-group.
 *
 * The composite primary key on `(idPlugins, flagKey, scope, scopeValue)`
 * makes duplicate rows impossible, even with concurrent writers. Empty
 * string for `scopeValue` is the convention for global rows so the
 * primary key stays NOT NULL.
 */
#[ORM\Entity(repositoryClass: PluginFeatureFlagRepository::class)]
#[ORM\Table(name: 'plugin_feature_flags')]
#[ORM\Index(name: 'idx_plugin_feature_flags_flag_key', columns: ['flag_key'])]
#[ORM\Index(name: 'fk_plugin_feature_flags_id_updated_by_users', columns: ['id_updated_by_users'])]
class PluginFeatureFlag
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ROLE = 'role';
    public const SCOPE_USER = 'user';
    public const SCOPE_GROUP = 'group';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Plugin $plugin;

    #[ORM\Id]
    #[ORM\Column(name: 'flag_key', type: Types::STRING, length: 100)]
    private string $flagKey;

    #[ORM\Id]
    #[ORM\Column(name: 'scope', type: Types::STRING, length: 20, options: ['default' => 'global', 'comment' => 'global | role | user | group'])]
    private string $scope = self::SCOPE_GLOBAL;

    #[ORM\Id]
    #[ORM\Column(name: 'scope_value', type: Types::STRING, length: 64, options: ['default' => '', 'comment' => 'Empty string for global scope'])]
    private string $scopeValue = '';

    #[ORM\Column(name: 'enabled', type: Types::BOOLEAN, options: ['default' => 0])]
    private bool $enabled = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_updated_by_users', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(Plugin $plugin, string $flagKey, string $scope = self::SCOPE_GLOBAL, string $scopeValue = '')
    {
        $this->plugin = $plugin;
        $this->flagKey = $flagKey;
        $this->scope = $scope;
        $this->scopeValue = $scopeValue;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getPlugin(): Plugin
    {
        return $this->plugin;
    }

    public function getFlagKey(): string
    {
        return $this->flagKey;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeValue(): string
    {
        return $this->scopeValue;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): self
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}
// ENTITY RULE
