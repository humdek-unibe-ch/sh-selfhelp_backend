<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Entity\Plugin;

use App\Entity\User;
use App\Repository\Plugin\PluginOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per plugin lifecycle event (install / update / disable / enable
 * / uninstall / purge / rollback / repair). The plugin manager's
 * orchestrators record every step here with snapshots and rollback
 * plans, so the admin UI and the doctor command can fully reconstruct
 * what happened.
 */
#[ORM\Entity(repositoryClass: PluginOperationRepository::class)]
#[ORM\Table(name: 'plugin_operations')]
#[ORM\Index(name: 'idx_plugin_operations_id_plugins', columns: ['id_plugins'])]
#[ORM\Index(name: 'idx_plugin_operations_plugin_id', columns: ['plugin_id'])]
#[ORM\Index(name: 'idx_plugin_operations_status', columns: ['status'])]
#[ORM\Index(name: 'idx_plugin_operations_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'fk_plugin_operations_id_requested_by_users', columns: ['id_requested_by_users'])]
class PluginOperation
{
    public const TYPE_INSTALL = 'install';
    public const TYPE_UPDATE = 'update';
    public const TYPE_DISABLE = 'disable';
    public const TYPE_ENABLE = 'enable';
    public const TYPE_UNINSTALL = 'uninstall';
    public const TYPE_PURGE = 'purge';
    public const TYPE_ROLLBACK = 'rollback';
    public const TYPE_REPAIR = 'repair';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Plugin::class)]
    #[ORM\JoinColumn(name: 'id_plugins', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Plugin $plugin = null;

    /**
     * Denormalized plugin id so we can find operations for a plugin
     * before its `plugins` row exists (first install).
     */
    #[ORM\Column(name: 'plugin_id', type: Types::STRING, length: 100)]
    private string $pluginId;

    #[ORM\Column(name: 'type', type: Types::STRING, length: 30, options: ['comment' => 'install | update | disable | enable | uninstall | purge | rollback | repair'])]
    private string $type;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, options: ['default' => 'requested', 'comment' => 'requested | running | succeeded | failed | cancelled | rolled_back'])]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(name: 'requested_version', type: Types::STRING, length: 50, nullable: true)]
    private ?string $requestedVersion = null;

    #[ORM\Column(name: 'from_version', type: Types::STRING, length: 50, nullable: true)]
    private ?string $fromVersion = null;

    #[ORM\Column(name: 'to_version', type: Types::STRING, length: 50, nullable: true)]
    private ?string $toVersion = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_requested_by_users', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'install_mode', type: Types::STRING, length: 20, options: ['default' => 'managed'])]
    private string $installMode = Plugin::INSTALL_MODE_MANAGED;

    /** @var array<string,mixed>|null */
    #[ORM\Column(name: 'snapshots_json', type: Types::JSON, nullable: true, options: ['comment' => 'Pre/post snapshots of plugin-owned rows'])]
    private ?array $snapshotsJson = null;

    /** @var array<string,mixed>|null */
    #[ORM\Column(name: 'rollback_plan_json', type: Types::JSON, nullable: true, options: ['comment' => 'Planned rollback actions'])]
    private ?array $rollbackPlanJson = null;

    /** @var array<int,array<string,mixed>>|null */
    #[ORM\Column(name: 'logs_json', type: Types::JSON, nullable: true, options: ['comment' => 'Array of log entries'])]
    private ?array $logsJson = null;

    #[ORM\Column(name: 'error_summary', type: Types::TEXT, nullable: true)]
    private ?string $errorSummary = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $pluginId, string $type)
    {
        $this->pluginId = $pluginId;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlugin(): ?Plugin
    {
        return $this->plugin;
    }

    public function setPlugin(?Plugin $plugin): self
    {
        $this->plugin = $plugin;
        return $this;
    }

    public function getPluginId(): string
    {
        return $this->pluginId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRequestedVersion(): ?string
    {
        return $this->requestedVersion;
    }

    public function setRequestedVersion(?string $requestedVersion): self
    {
        $this->requestedVersion = $requestedVersion;
        return $this;
    }

    public function getFromVersion(): ?string
    {
        return $this->fromVersion;
    }

    public function setFromVersion(?string $fromVersion): self
    {
        $this->fromVersion = $fromVersion;
        return $this;
    }

    public function getToVersion(): ?string
    {
        return $this->toVersion;
    }

    public function setToVersion(?string $toVersion): self
    {
        $this->toVersion = $toVersion;
        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): self
    {
        $this->requestedBy = $requestedBy;
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

    /** @return array<string,mixed>|null */
    public function getSnapshotsJson(): ?array
    {
        return $this->snapshotsJson;
    }

    /** @param array<string,mixed>|null $snapshotsJson */
    public function setSnapshotsJson(?array $snapshotsJson): self
    {
        $this->snapshotsJson = $snapshotsJson;
        return $this;
    }

    /** @return array<string,mixed>|null */
    public function getRollbackPlanJson(): ?array
    {
        return $this->rollbackPlanJson;
    }

    /** @param array<string,mixed>|null $rollbackPlanJson */
    public function setRollbackPlanJson(?array $rollbackPlanJson): self
    {
        $this->rollbackPlanJson = $rollbackPlanJson;
        return $this;
    }

    /** @return array<int,array<string,mixed>>|null */
    public function getLogsJson(): ?array
    {
        return $this->logsJson;
    }

    /** @param array<int,array<string,mixed>>|null $logsJson */
    public function setLogsJson(?array $logsJson): self
    {
        $this->logsJson = $logsJson;
        return $this;
    }

    /** @param array<string,mixed> $entry */
    public function appendLog(array $entry): self
    {
        $entry['ts'] ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $logs = $this->logsJson ?? [];
        $logs[] = $entry;
        $this->logsJson = $logs;
        return $this;
    }

    public function getErrorSummary(): ?string
    {
        return $this->errorSummary;
    }

    public function setErrorSummary(?string $errorSummary): self
    {
        $this->errorSummary = $errorSummary;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
// ENTITY RULE
