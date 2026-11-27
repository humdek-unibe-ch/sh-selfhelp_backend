<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Entity\Plugin;

use App\Entity\User;
use App\Repository\Plugin\PluginSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One value of a setting a plugin declares in its manifest `settings` (contract C14).
 *
 * The row belongs to the plugin by its manifest id (`plugin_id`, the text the manifest and the URLs use),
 * not by a foreign key to `plugins`: uninstalling a plugin deletes its `plugins` row but must keep what the
 * administrator entered, and a reinstall must find it again. Only a purge deletes the rows
 * ({@see \App\EventListener\PluginSettingsPurgeListener}).
 *
 * `value` is the text of the value as entered: the string or url itself, a number or a boolean in text form
 * (`1`, `2.5`, `1`/`0`). A secret (`is_secret`) is stored as `enc:v1:<base64>`, encrypted by
 * {@see \App\Plugin\Settings\PluginSettingsCrypto}; it is never returned by an API, only decrypted by
 * {@see \App\Plugin\Settings\PluginSettingsService::get()}.
 */
#[ORM\Entity(repositoryClass: PluginSettingRepository::class)]
#[ORM\Table(name: 'plugin_settings')]
#[ORM\UniqueConstraint(name: 'uq_plugin_settings_plugin_key', columns: ['plugin_id', 'setting_key'])]
#[ORM\Index(name: 'idx_plugin_settings_id_users_updated', columns: ['id_users_updated'])]
class PluginSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'plugin_id', type: Types::STRING, length: 100)]
    private string $pluginId;

    #[ORM\Column(name: 'setting_key', type: Types::STRING, length: 64)]
    private string $settingKey;

    #[ORM\Column(name: 'value', type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(name: 'is_secret', type: Types::BOOLEAN, options: ['default' => 0])]
    private bool $secret = false;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users_updated', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(string $pluginId, string $settingKey)
    {
        $this->pluginId = $pluginId;
        $this->settingKey = $settingKey;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    /** The stored text; for a secret the `enc:v1:` ciphertext, never the clear value. */
    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setSecret(bool $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?User $by): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedBy = $by;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
// ENTITY RULE
