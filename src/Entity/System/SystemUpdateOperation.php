<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Entity\System;

use App\Entity\User;
use App\Repository\System\SystemUpdateOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-scoped audit trail of CMS-initiated SelfHelp update requests.
 *
 * The CMS admin UI can REQUEST an update for the CURRENT instance only; the
 * actual Docker work is performed by the SelfHelp Manager (`sh-manager`), which
 * owns Docker access. The CMS never controls Docker — it records the operator's
 * intent here (status `requested`) and exposes the status the manager writes
 * back. `instanceId` is always the server-derived trusted identity, never a
 * value supplied by the browser.
 */
#[ORM\Entity(repositoryClass: SystemUpdateOperationRepository::class)]
#[ORM\Table(name: 'system_update_operations')]
#[ORM\UniqueConstraint(name: 'uq_system_update_operations_operation_id', columns: ['operation_id'])]
#[ORM\Index(name: 'idx_system_update_operations_instance_id', columns: ['instance_id'])]
#[ORM\Index(name: 'idx_system_update_operations_status', columns: ['status'])]
#[ORM\Index(name: 'idx_system_update_operations_requested_at', columns: ['requested_at'])]
#[ORM\Index(name: 'fk_system_update_operations_id_requested_by_users', columns: ['id_requested_by_users'])]
class SystemUpdateOperation
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_REJECTED = 'rejected';

    // Granular manager-driven lifecycle (distribution plan "Update states").
    // The longest value ("health_check_running") is 20 chars and fits the column.
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PREFLIGHT_RUNNING = 'preflight_running';
    public const STATUS_PREFLIGHT_FAILED = 'preflight_failed';
    public const STATUS_BACKUP_RUNNING = 'backup_running';
    public const STATUS_UPDATE_RUNNING = 'update_running';
    public const STATUS_MIGRATION_RUNNING = 'migration_running';
    public const STATUS_HEALTH_CHECK_RUNNING = 'health_check_running';
    public const STATUS_ROLLBACK_RUNNING = 'rollback_running';
    public const STATUS_ROLLBACK_FAILED = 'rollback_failed';

    /**
     * Statuses the SelfHelp Manager is allowed to write back through the
     * manager loop. The CMS-only `requested` state is intentionally excluded so
     * the manager can never re-open an operation as a fresh request.
     *
     * @var list<string>
     */
    public const MANAGER_WRITABLE_STATUSES = [
        self::STATUS_ACCEPTED,
        self::STATUS_PREFLIGHT_RUNNING,
        self::STATUS_PREFLIGHT_FAILED,
        self::STATUS_BACKUP_RUNNING,
        self::STATUS_UPDATE_RUNNING,
        self::STATUS_MIGRATION_RUNNING,
        self::STATUS_HEALTH_CHECK_RUNNING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_ROLLBACK_RUNNING,
        self::STATUS_ROLLED_BACK,
        self::STATUS_ROLLBACK_FAILED,
    ];

    /** Whether $status is a value the manager may write back. */
    public static function isManagerWritableStatus(string $status): bool
    {
        return in_array($status, self::MANAGER_WRITABLE_STATUSES, true);
    }

    /** Whether $status is terminal (no further manager write-backs expected). */
    public static function isTerminalStatus(string $status): bool
    {
        return in_array(
            $status,
            [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_ROLLED_BACK, self::STATUS_ROLLBACK_FAILED, self::STATUS_REJECTED],
            true
        );
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Server-derived trusted instance identity (never client-supplied). */
    #[ORM\Column(name: 'instance_id', type: Types::STRING, length: 190)]
    private string $instanceId;

    #[ORM\Column(name: 'operation_id', type: Types::STRING, length: 64)]
    private string $operationId;

    #[ORM\Column(name: 'target_version', type: Types::STRING, length: 50)]
    private string $targetVersion;

    #[ORM\Column(name: 'preflight_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $preflightId = null;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20, options: ['default' => 'requested', 'comment' => 'Operation lifecycle status; CMS writes requested, manager writes execution states'])]
    private string $status = self::STATUS_REQUESTED;

    #[ORM\Column(name: 'progress_percent', type: Types::INTEGER, options: ['default' => 0])]
    private int $progressPercent = 0;

    /** @var array<int,array<string,mixed>>|null */
    #[ORM\Column(name: 'steps_json', type: Types::JSON, nullable: true, options: ['comment' => 'Ordered execution steps written back by the manager'])]
    private ?array $stepsJson = null;

    #[ORM\Column(name: 'message', type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'accepted_migration_risk', type: Types::BOOLEAN, options: ['default' => 0])]
    private bool $acceptedMigrationRisk = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_requested_by_users', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $instanceId, string $operationId, string $targetVersion)
    {
        $this->instanceId = $instanceId;
        $this->operationId = $operationId;
        $this->targetVersion = $targetVersion;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->requestedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getOperationId(): string
    {
        return $this->operationId;
    }

    public function getTargetVersion(): string
    {
        return $this->targetVersion;
    }

    public function getPreflightId(): ?string
    {
        return $this->preflightId;
    }

    public function setPreflightId(?string $preflightId): self
    {
        $this->preflightId = $preflightId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this->touch();
    }

    public function getProgressPercent(): int
    {
        return $this->progressPercent;
    }

    public function setProgressPercent(int $progressPercent): self
    {
        $this->progressPercent = max(0, min(100, $progressPercent));
        return $this->touch();
    }

    /** @return array<int,array<string,mixed>>|null */
    public function getStepsJson(): ?array
    {
        return $this->stepsJson;
    }

    /** @param array<int,array<string,mixed>>|null $stepsJson */
    public function setStepsJson(?array $stepsJson): self
    {
        $this->stepsJson = $stepsJson;
        return $this->touch();
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this->touch();
    }

    public function isAcceptedMigrationRisk(): bool
    {
        return $this->acceptedMigrationRisk;
    }

    public function setAcceptedMigrationRisk(bool $acceptedMigrationRisk): self
    {
        $this->acceptedMigrationRisk = $acceptedMigrationRisk;
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

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this;
    }
}
// ENTITY RULE
