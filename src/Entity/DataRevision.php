<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One event in the history of one data record: who changed it, when, how and
 * through which door. What changed is in {@see DataRevisionChange}; the values
 * are in {@see DataRevisionValue}.
 *
 * A revision exists only for a change that was really persisted; a save that
 * changes nothing creates none. `version` is the record's version after this
 * event and is unique per record, so two writers can never both create the same
 * version. Rows are written by `DataRevisionService` in the same database
 * transaction as the data they describe.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_revisions')]
#[ORM\UniqueConstraint(name: 'uq_data_revisions_row_version', columns: ['id_data_rows', 'version'])]
#[ORM\Index(name: 'idx_data_revisions_table_time', columns: ['id_data_tables', 'created_at'])]
class DataRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DataRow::class)]
    #[ORM\JoinColumn(name: 'id_data_rows', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?DataRow $dataRow = null;

    #[ORM\ManyToOne(targetEntity: DataTable::class)]
    #[ORM\JoinColumn(name: 'id_data_tables', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?DataTable $dataTable = null;

    /** The record's version after this event (1 = created). */
    #[ORM\Column(name: 'version', type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** The effective actor. Detached (NULL) when that user is removed; the content stays. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_users', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** The administrator behind an impersonated session, when there was one. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'id_impersonating_users', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $impersonatingUser = null;

    /** Lookup group `dataRevisionOperations`. */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_operations', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $operation = null;

    /** Lookup group `dataRevisionSources`. */
    #[ORM\ManyToOne(targetEntity: Lookup::class)]
    #[ORM\JoinColumn(name: 'id_sources', referencedColumnName: 'id', nullable: false)]
    private ?Lookup $source = null;

    /** The audit event of the same write (`transactions`); NULL when that audit row is gone. */
    #[ORM\ManyToOne(targetEntity: Transaction::class)]
    #[ORM\JoinColumn(name: 'id_transactions', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Transaction $transaction = null;

    /** For a restore: the version whose content was applied as a new revision. */
    #[ORM\Column(name: 'restored_from_version', type: Types::INTEGER, nullable: true)]
    private ?int $restoredFromVersion = null;

    /** A checkpoint also stores the full state after it, so reconstruction can start here. */
    #[ORM\Column(name: 'is_checkpoint', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCheckpoint = false;

    /** Version of the storage format of this revision. */
    #[ORM\Column(name: 'schema_version', type: Types::SMALLINT, options: ['default' => 1])]
    private int $schemaVersion = 1;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDataRow(): ?DataRow
    {
        return $this->dataRow;
    }

    public function setDataRow(?DataRow $dataRow): static
    {
        $this->dataRow = $dataRow;

        return $this;
    }

    public function getDataTable(): ?DataTable
    {
        return $this->dataTable;
    }

    public function setDataTable(?DataTable $dataTable): static
    {
        $this->dataTable = $dataTable;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getImpersonatingUser(): ?User
    {
        return $this->impersonatingUser;
    }

    public function setImpersonatingUser(?User $impersonatingUser): static
    {
        $this->impersonatingUser = $impersonatingUser;

        return $this;
    }

    public function getOperation(): ?Lookup
    {
        return $this->operation;
    }

    public function setOperation(?Lookup $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getSource(): ?Lookup
    {
        return $this->source;
    }

    public function setSource(?Lookup $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): static
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getRestoredFromVersion(): ?int
    {
        return $this->restoredFromVersion;
    }

    public function setRestoredFromVersion(?int $restoredFromVersion): static
    {
        $this->restoredFromVersion = $restoredFromVersion;

        return $this;
    }

    public function isCheckpoint(): bool
    {
        return $this->isCheckpoint;
    }

    public function setIsCheckpoint(bool $isCheckpoint): static
    {
        $this->isCheckpoint = $isCheckpoint;

        return $this;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function setSchemaVersion(int $schemaVersion): static
    {
        $this->schemaVersion = $schemaVersion;

        return $this;
    }
}
